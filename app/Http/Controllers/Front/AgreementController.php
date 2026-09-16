<?php

namespace App\Http\Controllers\Front;

/**
 * 同意画面（P-32、7.7 / 4.3.7）。同意の操作は Livewire コンポーネント
 * （`Front\Reservation\Agreement`）が担う。
 * ページの組み立ては `ReservationStepController` が持つ（4.3.11）。
 */
class AgreementController extends ReservationStepController
{
    /**
     * @return view-string
     */
    protected function view(): string
    {
        return 'front.reservation.agreement';
    }
}
