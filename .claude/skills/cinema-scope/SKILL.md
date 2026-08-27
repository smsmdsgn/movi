---
name: cinema-scope
description: 館に紐づくデータを扱う機能を追加・修正するとき。docs/design.md 4.1 / 13.4.1 を参照する。
allowed-tools: Read, Grep, Glob
---

館スコープに関する規約。詳細は `docs/design.md` 4.1 / 13.4.1 を参照する。

- 顧客側は `ResolveCinema` ミドルウェアで解決した館を使用する。再取得しない
- 管理画面は `CinemaScope` により自動で絞り込まれる
- 画面ごとに権限判定の条件式を記述しない
- `withoutGlobalScope()` を使用しない
- 館切替時は同種のページへ遷移させる
