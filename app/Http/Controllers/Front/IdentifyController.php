<?php

namespace App\Http\Controllers\Front;

/**
 * 会員／非会員の選択（P-33、7.8）。選択の操作は Livewire コンポーネント
 * （`Front\Reservation\Identify`）が担う。
 * ページの組み立ては `ReservationStepController` が持つ（4.3.11）。
 */
class IdentifyController extends ReservationStepController
{
    /**
     * @return view-string
     */
    protected function view(): string
    {
        return 'front.reservation.identify';
    }
}
