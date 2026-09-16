<?php

namespace App\Http\Controllers\Front;

/**
 * 座席選択（P-31、7.6）。座席表そのものは Livewire コンポーネント
 * （`Front\Reservation\SeatSelection`）が描画する。
 * ページの組み立ては `ReservationStepController` が持つ（4.3.11）。
 */
class SeatSelectionController extends ReservationStepController
{
    /**
     * @return view-string
     */
    protected function view(): string
    {
        return 'front.reservation.seats';
    }
}
