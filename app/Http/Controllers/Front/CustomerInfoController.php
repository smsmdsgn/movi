<?php

namespace App\Http\Controllers\Front;

/**
 * お客様情報の入力（P-34、7.9 / 4.3.6）。入力と検証は Livewire コンポーネント
 * （`Front\Reservation\CustomerInfo`）が担う。
 * ページの組み立ては `ReservationStepController` が持つ（4.3.11）。
 */
class CustomerInfoController extends ReservationStepController
{
    /**
     * @return view-string
     */
    protected function view(): string
    {
        return 'front.reservation.customer';
    }
}
