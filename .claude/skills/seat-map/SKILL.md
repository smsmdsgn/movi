---
name: seat-map
description: 座席表UI・座席データを実装するとき。docs/design.md 6.3 / 7.3 を参照する。
allowed-tools: Read, Grep, Glob
---

座席表の実装に関する規約。詳細は `docs/design.md` 6.3 / 7.3 を参照する。

- 表示ラベル（`row_label` / `seat_number`）と座標（`grid_row` / `grid_col`）を分離する
- 通路には座席レコードを作成しない
- 描画は CSS Grid。SVG を使用しない
- 座席の状態を色のみで区別しない
- 全座席種別を1マス1席として扱う
