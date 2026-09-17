<?php

namespace App\Http\Controllers\Front;

/**
 * 決済（P-36、7.11）。支払方法の選択とカード情報の入力は Livewire コンポーネント
 * （`Front\Reservation\Payment`）が担う。
 * ページの組み立ては `ReservationStepController` が持つ（4.3.11）。
 */
class PaymentController extends ReservationStepController
{
    /**
     * @return view-string
     */
    protected function view(): string
    {
        return 'front.reservation.payment';
    }
}
