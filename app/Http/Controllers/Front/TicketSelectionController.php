<?php

namespace App\Http\Controllers\Front;

/**
 * 券種選択（P-35、7.10）。選択と金額の表示は Livewire コンポーネント
 * （`Front\Reservation\TicketSelection`）が担う。
 * ページの組み立ては `ReservationStepController` が持つ（4.3.11）。
 */
class TicketSelectionController extends ReservationStepController
{
    /**
     * @return view-string
     */
    protected function view(): string
    {
        return 'front.reservation.tickets';
    }
}
