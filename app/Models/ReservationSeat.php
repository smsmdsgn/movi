<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $reservation_id
 * @property int $screening_id
 * @property int $seat_id
 * @property int $ticket_type_id
 * @property int $amount
 * @property CarbonImmutable|null $released_at
 * @property int|null $active_seat_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['reservation_id', 'screening_id', 'seat_id', 'ticket_type_id', 'amount'])]
class ReservationSeat extends Model
{
    protected $table = 't_reservation_seats';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'released_at' => 'datetime',
            'active_seat_id' => 'integer',
        ];
    }

    /**
     * 座席を占有中の行（`released_at IS NULL`、6.4.2）。残席の集計（7.4）が参照する。
     * `ReservationService`（13.4.7）実装時も同じ条件を本スコープに寄せること。
     *
     * @param  Builder<ReservationSeat>  $query
     * @return Builder<ReservationSeat>
     */
    #[Scope]
    protected function occupying(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    /**
     * @return BelongsTo<Screening, $this>
     */
    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    /**
     * @return BelongsTo<Seat, $this>
     */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class, 'seat_id');
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }
}
