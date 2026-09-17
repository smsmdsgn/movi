<?php

namespace App\Services;

/**
 * 座席1席分の金額の内訳（6.5.4）。`PriceBreakdown` が保持する。
 *
 * **割引は席ごとに確定させる**（6.5.4）。予約単位の割引額を席へ按分すると、
 * 端数の配分規則を別途定める必要が生じ、席ごとの金額を保持する 6.5.5 と噛み合わない。
 */
final readonly class SeatPrice
{
    /**
     * @param  int  $regularAmount  割引前の金額（券種価格 + 上映編成の追加料金 + 座席種別の追加料金）
     * @param  int  $discountAmount  この席に適用した割引額。`$regularAmount` を超えない（6.5.2-6）
     */
    public function __construct(
        public int $seatId,
        public int $ticketTypeId,
        public int $regularAmount,
        public int $discountAmount,
    ) {}

    /**
     * 確定額。`t_reservation_seats.amount` に保存する値（6.5.5）。
     *
     * `$discountAmount` が `$regularAmount` を超えないため、負にならない
     * （列は `unsignedInteger`）。
     */
    public function amount(): int
    {
        return $this->regularAmount - $this->discountAmount;
    }
}
