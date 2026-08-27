---
name: admin-screen
description: 管理画面の画面・機能を追加するとき。docs/design.md 4.8 / 13.4.1 / 13.4.2 を参照する。
allowed-tools: Read, Grep, Glob
---

管理画面の実装に関する規約。詳細は `docs/design.md` 4.8 / 13.4.1 / 13.4.2 を参照する。

- 権限ごとに画面を分けない。同一画面をスコープと表示項目で切り替える
- `cinema-admin` には館セレクタを表示しない
- 権限判定は Policy に集約し、ロール名を直接比較しない
- 予約状況にメールアドレスと決済情報を表示しない
- 更新は Eloquent モデル経由で行い、一括更新（`where()->update()`）を使用しない（5.5）
