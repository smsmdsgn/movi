---
name: pricing
description: 料金・割引・券種を実装・変更するとき。docs/design.md 6.5 / 13.4.5 を参照する。
allowed-tools: Read, Grep, Glob
---

料金計算に関する規約。詳細は `docs/design.md` 6.5 / 13.4.5 を参照する。

- 計算は `PricingService` に集約する
- 上映規格の追加料金は `t_bookings.surcharge` に確定済み。購入フローで加算しない
- 割引は重複適用せず、安価な方を採用する
- 無料鑑賞券と割引は併用しない
- 予約成立時点の金額を `t_reservation_seats` に保存する
- クライアントから送られた金額を信用しない
