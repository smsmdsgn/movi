# 祇園ムビ（Gion Movi）

架空のシネマコンプレックスチェーン「ムビ」の公式サイト。

京都のシネマコンプレックスを想定した映画館サイトとして、
上映スケジュールの閲覧から座席指定予約、決済、QRコードによる入場管理までを扱う。

- 基本設計書: [`docs/design.md`](docs/design.md)

---

## 技術構成

| 区分 | 内容 |
|---|---|
| フレームワーク | Laravel 13 |
| PHP | 8.4 |
| フロントエンド | Livewire 4 / Alpine.js / Tailwind CSS / Flux UI |
| データベース | MariaDB 10.11 |
| 決済 | Stripe（テストモード） |
| 映画情報 | TMDB API |
| メール | Brevo（SMTPリレー） |
| ボット対策 | Cloudflare Turnstile |

---

## 主な実装内容

| 機能 | 概要 |
|---|---|
| 系列館の切替 | URL単位で館を切り替え、上映情報・予約・お知らせを当該館にスコープする |
| 座席指定予約 | 客席形状を再現した座席表からの選択。座席ロックによる排他制御を伴う |
| 券種と料金 | 券種マスタ、上映規格・座席種別の追加料金、時間帯および枚数による割引 |
| 決済 | Stripe Elements による埋め込み決済。金額はサーバー側で再計算する |
| キャンセルと返金 | 上映開始20分前までの取消。キャンセル済み座席の再販に対応する |
| スタンプカード | 鑑賞回数に応じた無料鑑賞券の発行 |
| 入場管理 | QRコードの発行と、ゲート端末での読み取りによる入場処理 |
| 管理画面 | 本部・劇場・ゲート端末の3階層の権限分掌 |
| コンテンツ管理 | お知らせ（カテゴリー別アーカイブ）、バナー |

### 設計上の主な論点

設計判断の根拠は基本設計書に **【根拠】** として記載している。

| 論点 | 該当章 |
|---|---|
| 座席の排他制御（ロックの取得・延長・解放） | 6.4 |
| キャンセル済み座席の再販（生成列によるユニーク制約） | 6.4.2 |
| 上映編成と上映回の階層分離による権限分掌 | 4.8.3 |
| 権限スコープの一元管理 | 4.8.1 |
| 料金計算とサーバー側での金額確定 | 6.5 |
| セキュリティ設計 | 17章 |

---

## セットアップ

### 前提

本プロジェクトは Windows と macOS の双方で開発できる。
以下の手順は両OSに対応している。

| 項目 | バージョン |
|---|---|
| PHP | 8.4 |
| Composer | 2.x |
| Node.js | 20 以上 |
| MariaDB | 10.11 |
| Git | 2.x |

**XAMPP / MAMP は使用しない。** 同梱される PHP が 8.2 までであり、本プロジェクトの要件を満たさない。

#### 開発環境の構築（Laravel Herd）

PHP と Web サーバーには **Laravel Herd** を使用する。
Windows と macOS の双方に対応し、PHP 7.4〜8.5 を同梱してバージョンを切り替えられる。
Composer と Laravel インストーラも同梱されるため、個別の導入が不要になる。

**1. 既存の PHP 環境を停止する**

XAMPP・MAMP・WampServer などを導入している場合、以下を実施する。

1. Apache と MySQL のサービスを停止する（ポート80と3306が競合する）
2. 環境変数 Path から PHP のパス（`C:\xampp\php` など）を削除する

Path に残っていると、Herd を導入しても既存の PHP が優先されて参照される。
使用しないのであればアンインストールが確実。

**2. Herd を導入する**

`https://herd.laravel.com` からインストーラを取得し、実行する。

Herd は **Herd paths** に登録されたディレクトリの配下を `.test` ドメインで配信する。
既定では `~/Herd` が登録されている。
別の場所を使う場合は、設定画面の General → Herd paths → **Add path** で追加する。

**3. PHP 8.4 を有効にする**

Herd の PHP 設定画面から 8.4 をインストールし、既定のバージョンに設定する。

ターミナルを開き直してから確認する。

```bash
php -v
```

`PHP 8.4.x` と表示されること。

**別のバージョンが表示される場合**

既存の PHP が PATH 上で Herd より優先されている。

```powershell
# Windows (PowerShell)
where.exe php
```

```bash
# macOS
which -a php
```

XAMPP 等のパスが先に表示される場合、環境変数 Path から該当のパスを削除する。
削除後、ターミナル（およびエディタ）を再起動してから確認し直す。

`ZTS` や `Xdebug` の表記がある場合、XAMPP の PHP を参照している可能性が高い。

**4. 必要な拡張機能を確認する**

```bash
# macOS
php -m | grep -E "gd|pdo_mysql|mbstring|bcmath|fileinfo"

# Windows (PowerShell)
php -m | Select-String -Pattern "gd|pdo_mysql|mbstring|bcmath|fileinfo"
```

`gd` が含まれていること。QRコードの生成に使用する。

#### データベースの導入（MariaDB 10.11）

Herd の無料版にはデータベース管理機能が含まれないため、個別に導入する。

**推奨: Docker で導入する**

Docker Desktop を導入する。
`compose.yaml` はプロジェクト作成後に `~/Herd/movi` へ配置する。

```yaml
services:
  mariadb:
    image: mariadb:10.11
    container_name: movi-mariadb
    restart: unless-stopped
    ports:
      - "127.0.0.1:3306:3306"
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_DATABASE: movi
      MARIADB_USER: movi
      MARIADB_PASSWORD: movi
      TZ: Asia/Tokyo
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    volumes:
      - movi-mariadb:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  movi-mariadb:
```

```bash
docker compose up -d
docker compose ps
```

`STATUS` が `healthy` になれば起動完了。

> XAMPP や MySQL がすでに動作している場合、ポート3306が競合する。
> 事前に停止すること。

**代替: ネイティブに導入する**

Docker を使用しない場合は個別に導入する。

| OS | 方法 |
|---|---|
| Windows | `https://mariadb.org/download/` から 10.11 系の MSI インストーラ |
| macOS | `brew install mariadb@10.11` → `brew services start mariadb@10.11` |

この場合、`compose.yaml` は使用せず、後述の「データベースの作成」を手動で実施する。

**接続確認**

```bash
# Docker の場合
docker compose exec mariadb mariadb -u root -proot -e "SELECT VERSION();"

# ネイティブの場合
mysql -u root -p -e "SELECT VERSION();"
```

`10.11.x-MariaDB` と表示されること。

#### Node.js の導入

Herd Pro には nvm が同梱されるが、無料版には含まれない。個別に導入する。

| OS | 方法 |
|---|---|
| Windows | `https://nodejs.org` から LTS 版のインストーラ、または `nvm-windows` |
| macOS | `brew install node` または `nvm` |

```bash
node -v   # v20 以上
npm -v
```

#### 外部サービスのキー

以下を取得しておくこと。実装中に必要になる。

| サービス | 取得するもの |
|---|---|
| Stripe | テストモードの公開キーとシークレットキー |
| TMDB | API キー |
| Brevo | SMTP のホスト・ポート・ユーザー名・パスワード |
| Cloudflare Turnstile | 不要（公開されているテスト用キーを使用する） |

#### 導入後の確認

```bash
php -v          # PHP 8.4.x
composer -V     # Composer 2.x
node -v         # v20 以上
git --version   # 2.x
docker -v       # Docker を使用する場合
```

### 1. Laravel インストーラの確認

Herd には Laravel インストーラが同梱されている。

```bash
laravel --version
```

コマンドが見つからない場合、または古い場合は個別に導入・更新する。

```bash
composer global require laravel/installer
composer global update laravel/installer
```

> **インストーラが古いとスターターキットの選択肢が表示されず、
> 旧バージョンの Laravel が作成される。** 必ず更新すること。

`laravel` コマンドが見つからない場合、Composer のグローバル bin ディレクトリに
PATH を通す。

| OS | パス |
|---|---|
| macOS | `~/.composer/vendor/bin` または `~/.config/composer/vendor/bin` |
| Windows | `%USERPROFILE%\AppData\Roaming\Composer\vendor\bin` |

### 2. プロジェクトの作成

Herd paths に登録したディレクトリの直下に作成する。

```bash
cd ~/Herd
laravel new movi --livewire
cd movi
```

以降、本書では `~/Herd/movi` を例として記載する。
別のディレクトリを使用する場合は読み替えること。

対話で以下を選択する。

| 質問 | 選択 |
|---|---|
| Which authentication features would you like to enable? | **Registration のみ** |
| テストフレームワーク | Pest |
| `npm install` の実行 | Yes |

認証機能の選択について、既定では全項目が選択されている。
スペースキーで Registration 以外を解除する。

| 項目 | 選択 | 理由 |
|---|---|---|
| Email verification | 無効 | 登録直後に利用できない状態を作らないため（設計書 8.3） |
| Registration | **有効** | 会員登録機能に使用する |
| Two-factor authentication | 無効 | 適用範囲外（設計書 17.14） |
| Passkeys | 無効 | 仕様に含まない |
| Password confirmation | 無効 | 仕様に含まない |

不要な機能を有効にすると、使用しない画面・ルート・マイグレーションが生成される。

#### Laravel Boost の設定

プロジェクト作成の途中で Laravel Boost のインストーラが起動する。
AI エージェント向けのガイドライン、スキル、MCP サーバーを構成するツール。

| 質問 | 選択 |
|---|---|
| Which Boost features would you like to configure? | `guidelines` / `skills` / `mcp` の**すべて** |
| Which third-party AI guidelines/skills would you like to install? | **None** |
| Which integrations would you like to configure for Boost? | **None** |
| Which AI agents would you like to configure? | **`claude_code` のみ** |

**選択の理由**

| 項目 | 判断 |
|---|---|
| guidelines | Laravel の規約をセッション開始時に読み込ませる |
| skills | `composer.json` から Livewire・Pest・Flux UI の作法が自動導入される |
| mcp | エージェントが DB スキーマ・設定値・ブラウザログを直接参照できる。実装精度に直結する |
| livewire/blaze | 本プロジェクトで使用しない |
| Laravel Cloud | デプロイ先が異なる |
| Claude Code 以外のエージェント | 使用しない。設定ファイルが不要に生成される |

**生成されるファイルの扱い**

Boost は `CLAUDE.md`・`.mcp.json`・`boost.json` を生成する。

`CLAUDE.md` の `<laravel-boost-guidelines>` タグの内側は
`boost:install` および `boost:update` で再生成されるため、直接編集しない。

**プロジェクト固有の規約は、同タグの外側（上部）に記述する。**

```markdown
# 祇園ムビ（Gion Movi）

（プロジェクト固有の規約）

<laravel-boost-guidelines>
（Boost が生成する内容。編集しない）
</laravel-boost-guidelines>
```

Boost のガイドラインは Laravel 一般の作法を、
タグ外の記述は本プロジェクト固有の規約を扱う。役割が異なるため併存させる。

作成後、ブラウザで `http://movi.test` を開き、Laravel の初期画面が表示されることを確認する。

> Herd paths の配下に作成しない場合は、Herd の「Add site」で
> ディレクトリを個別に登録し、ドキュメントルートに `public` を指定する。

**バージョンを確認する。**

```bash
php artisan --version
```

`Laravel Framework 13.x.x` と表示されること。
12系が表示された場合は以下を実施する。

1. 作成したディレクトリを削除する
2. `composer global update laravel/installer` でインストーラを更新する
3. 本手順をやり直す

> スターターキットを使わず素のスケルトンから始める場合は
> `composer create-project laravel/laravel:^13.0 movi` を使う。
> ただし認証機能が含まれないため、本プロジェクトでは採用しない。

### 3. データベースの作成

Docker で導入した場合、`compose.yaml` の設定により
データベースとユーザーが自動的に作成される。本手順は不要。

ネイティブに導入した場合は以下を実行する。

```bash
mysql -u root -p
```

```sql
CREATE DATABASE movi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'movi'@'localhost' IDENTIFIED BY '{任意のパスワード}';
GRANT ALL PRIVILEGES ON movi.* TO 'movi'@'localhost';
FLUSH PRIVILEGES;
```

### 4. 環境変数の設定

```bash
cp .env.example .env
php artisan key:generate
```

`.env` に以下を設定する。

```dotenv
APP_TIMEZONE=Asia/Tokyo

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=movi
DB_USERNAME=movi
DB_PASSWORD=movi

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=MOVI

STRIPE_KEY=
STRIPE_SECRET=

TMDB_API_KEY=

TURNSTILE_SITE_KEY=1x00000000000000000000AA
TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA
```

設定した項目を `.env.example` にも反映する（値は空にすること）。

### 5. 設定ファイルの配置

以下を配置する。
`CLAUDE.md` は Boost が生成済みのため、タグの外側に内容を追記する（手順2の注記を参照）。

```
~/Herd/movi/
├── README.md              本ファイル
├── CLAUDE.md              プロジェクト全体の前提と規約
├── .gitattributes         改行コードの統一
├── compose.yaml           MariaDB（Docker を使用する場合）
├── docs/
│   └── design.md          基本設計書
└── .claude/
    └── skills/
        └── mycommit/
            └── SKILL.md   コミット用スキル
```

`.gitignore` に以下が含まれていることを確認し、不足していれば追記する。

Laravel 標準の `.gitignore` に以下を追記する。

```
.env.*
!.env.example
/storage/app/public/*
!/storage/app/public/.gitkeep
.htpasswd
```

`/storage/app/public/*` を指定しないと、アップロードされた画像がコミット対象になる。

> **`.env` が `.gitignore` に含まれていることを、最初のコミット前に必ず確認すること。**
> 一度コミットするとコミット履歴から削除できない。

### 6. Git の初期化

```bash
git init
git add .
git status
```

`git status` の出力に `.env` が含まれていないことを確認する。

```bash
git commit -m "chore: プロジェクトの初期構成を作成"
git branch -m main
git branch develop
```

GitHub でリポジトリを作成する。README・.gitignore・ライセンスは追加しない。

```bash
git remote add origin git@github.com:{ユーザー名}/movi.git
git push -u origin main
git push -u origin develop
```

以降、実装は `develop` から `feature/*` ブランチを作成して行う。

### 7. 開発基盤の生成（Claude Code）

Claude Code を起動し、以下を指示する。

```
docs/design.md を読み、以下を生成してください。

1. 14.3 の参照型スキル（S-01〜S-16）を .claude/skills/ 配下に
2. 16.4 のレビュー用サブエージェント（A-01〜A-03）を .claude/agents/ 配下に
3. lang/ja のバリデーションメッセージ

生成後、内容を提示してください。まだ実装には入らないでください。
```

`.claude/skills/` には Laravel Boost が生成したスキルが既に存在する。
内容が重複する場合は整理する。

生成された内容を確認し、`/mycommit` でコミットする。

> スキルとサブエージェントが揃う前に実装へ着手しないこと。
> 規約が適用されないままコードが積み上がる。

### 8. 実装の開始

```
docs/design.md 11.1 の工程1「基盤構築」を実施してください。
DB設計とマイグレーションから着手し、シーダーは次の工程とします。
```

---

## 日常の開発

### 起動

```bash
cd ~/Herd/movi
docker compose up -d
npm run dev
```

`http://movi.test` でアクセスする。

### シーダーの実行

初期データとして過去2年分の上映実績と予約データを生成するため、
実行に時間を要する。開発中は生成期間を短縮できる。

```php
// database/seeders/Constants/SeedConfig.php

public const SEED_MONTHS_BACK = 1;   // 開発時
// public const SEED_MONTHS_BACK = 24;  // 最終確認時
```

```bash
php artisan migrate:fresh --seed
```

### 動作確認用のテストカード

Stripe のテストモードで使用する。実際の課金は発生しない。

| 用途 | カード番号 |
|---|---|
| 決済成功 | `4242 4242 4242 4242` |
| 決済失敗（カード拒否） | `4000 0000 0000 0002` |

有効期限は未来の任意の日付、CVC は任意の3桁。

### 2台目の環境を用意する場合

「前提」の手順で Herd と MariaDB を導入したうえで、以下を実行する。

```bash
cd ~/Herd
git clone git@github.com:{ユーザー名}/movi.git
cd movi
composer install
npm install
cp .env.example .env
php artisan key:generate
# .env を編集し、キーを設定する
docker compose up -d
php artisan migrate --seed
```

| 項目 | 備考 |
|---|---|
| 改行コード | `.gitattributes` により LF に統一される。`core.autocrlf` を設定しない |
| PHP のバージョン | 両環境とも Herd で 8.4 に揃える |
| `.env` | リポジトリに含まれないため、環境ごとに作成する |
| DB | `docker compose up -d` で起動する。データは環境ごとに独立する |

---

## デプロイ

手順は基本設計書 15.3 を参照。

```bash
# 開発機でビルド
npm run build

# アップロード対象: アプリケーションコード および public/build
# 除外: storage/ .env

# サーバー側
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`storage/` を削除・上書きしないこと。アップロード済みの画像が失われる。

---

## 開発時の確認手順

各タスクの完了時に以下を実行する（基本設計書 16.2）。

```bash
vendor/bin/pint --dirty      # コード整形
vendor/bin/phpstan analyse   # 静的解析
php artisan test             # テスト
git diff --stat              # 変更範囲の確認
```

そのうえで、`.claude/agents/` に定義したレビュー用サブエージェントで
実装内容と影響範囲を確認する。

---

## 注意事項

| # | 内容 |
|---|---|
| 1 | Stripe はテストモードのみ。実際の課金は発生しない |
| 2 | お問い合わせフォームはダミー実装であり、メールは送信されない |
| 3 | 予約確定メール等は実際に送信される |
| 4 | 決済手段として表示する「HogePay」「FugaPay」「Mogo払い」は架空のサービス |
| 5 | 上映規格「MOVI GRAND」等は架空の名称であり、実在の規格とは無関係 |
| 6 | 各館の所在地は実在する鉄道駅の住所を借用したもの |
| 7 | 映画情報は TMDB API から取得している。TMDB により承認・認証されたものではない |
