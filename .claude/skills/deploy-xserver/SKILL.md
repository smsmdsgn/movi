---
name: deploy-xserver
description: デプロイ・環境構築を行うとき。docs/design.md 3.4 / 15章 を参照する。
allowed-tools: Read, Grep, Glob
---

デプロイ・環境構築に関する規約。詳細は `docs/design.md` 3.4 / 15章 を参照する。

- アセットビルドはローカルで実行し、`public/build` をアップロードする
- `storage/` を削除・上書きしない
- CLI 用 PHP のバージョンを 8.4 に揃える
- `public_html` を `public` へのシンボリックリンクとする
- `storage:link` を実行する
- Cron に `schedule:run` を毎分実行する設定を追加する
