<?php

namespace App\Http\Controllers\Front;

/**
 * 予約確認（P-37、7.12）。内容の確認と確定は Livewire コンポーネント
 * （`Front\Reservation\Confirm`）が担う。
 * ページの組み立ては `ReservationStepController` が持つ（4.3.11）。
 */
class ConfirmController extends ReservationStepController
{
    /**
     * @return view-string
     */
    protected function view(): string
    {
        return 'front.reservation.confirm';
    }
}
