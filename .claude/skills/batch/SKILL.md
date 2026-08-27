---
name: batch
description: スケジュールコマンドを実装・変更するとき。docs/design.md 10章 を参照する。
allowed-tools: Read, Grep, Glob
---

スケジュールコマンドの実装に関する規約。詳細は `docs/design.md` 10章 を参照する。

- 10章の B-01 〜 B-03 を `Console/Commands` に実装する
- 多重実行に耐える実装とする（ユニーク制約または条件による除外）
- `schedule:run` の停止が機能停止に直結することを前提に、冪等性を確保する
