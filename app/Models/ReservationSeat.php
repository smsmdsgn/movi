<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reservation_id
 * @property int $screening_id
 * @property int $seat_id
 * @property int $ticket_type_id
 * @property int $amount
 * @property Carbon|null $released_at
 * @property int|null $active_seat_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
