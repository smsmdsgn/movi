---
name: seo
description: メタ情報・構造化データ・sitemap を実装するとき。docs/design.md 19章 を参照する。
allowed-tools: Read, Grep, Glob
---

SEOに関する規約。詳細は `docs/design.md` 19章 を参照する。

- `title` は19.2の形式に従う
- `canonical` / OGP / Twitter Card を全ページに設定する
- 構造化データは館ページに `MovieTheater`、作品詳細に `Movie`
- `sitemap.xml` に上映回を含めない
- `robots.txt` で予約フロー・マイページ・予約照会・管理画面を除外する
