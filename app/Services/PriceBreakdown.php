<?php

namespace App\Services;

use App\Enums\Discount;

/**
 * 予約1件分の金額（13.4.5）。`PricingService::calculate()` / `calculateResolved()` が組み立てる。
 *
 * 小計・割引額・支払金額は席ごとの内訳（`SeatPrice`）から導く。合計を別に保持すると、
 * 内訳と食い違った値を持てるようになる。
 */
final readonly class PriceBreakdown
{
    /**
     * @param  list<SeatPrice>  $seats  席ごとの内訳。座席IDの昇順
     * @param  Discount|null  $discount  適用した割引（6.5.2）。無割引・無料鑑賞券の使用時は null
     * @param  int|null  $freeTicketId  使用した無料鑑賞券（4.5.2）。未使用は null
     */
    public function __construct(
        public array $seats,
        public ?Discount $discount = null,
        public ?int $freeTicketId = null,
    ) {}

    /** 小計（選択した全席の割引前の合計、6.5.4）。 */
    public function subtotal(): int
    {
        return array_sum(array_map(fn (SeatPrice $seat) => $seat->regularAmount, $this->seats));
    }

    /**
     * 割引額（6.5.4）。**無料鑑賞券による減額を含む。**
     *
     * 6.5.2-2 により両者は併用しないため、この値の由来は `discount` と `freeTicketId` の
     * いずれか一方で判別できる。
     */
    public function discountAmount(): int
    {
        return array_sum(array_map(fn (SeatPrice $seat) => $seat->discountAmount, $this->seats));
    }

    /** 支払金額（6.5.4）。席ごとの確定額が負にならないため、この値も負にならない。 */
    public function total(): int
    {
        return array_sum(array_map(fn (SeatPrice $seat) => $seat->amount(), $this->seats));
    }

    /** 枚数（4.3.4 の上限の対象）。 */
    public function seatCount(): int
    {
        return count($this->seats);
    }

    /**
     * 決済フローをスキップできるか（4.5.2「決済のスキップ条件」）。
     *
     * 追加料金または同伴者分が残る場合は通常の決済画面へ遷移する。
     */
    public function isFullyCovered(): bool
    {
        return $this->seats !== [] && $this->total() === 0;
    }
}
