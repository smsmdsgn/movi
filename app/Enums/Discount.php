<?php

namespace App\Enums;

/**
 * 予約に適用した割引（6.5.2）。**同時に2つは適用しない。**
 *
 * 券種価格は A-07 から可変（1〜10,000円）のため、割引額は価格に依存する。
 * 判定と計算は `PricingService` が持つ（13.4.5）。
 */
enum Discount: string
{
    /** レイトショー。上映回の開始時刻が20:00以降のとき、対象の各席から定額を引く。 */
    case LateShow = 'late_show';

    /** ペア割。大人券種を2枚単位で、券種価格を定額に置き換える。 */
    case Pair = 'pair';

    /**
     * 券種選択（P-35、7.10-3）と予約確認（P-37）に出す名称。
     */
    public function label(): string
    {
        return __('front.reservation.discount.'.$this->value);
    }
}
