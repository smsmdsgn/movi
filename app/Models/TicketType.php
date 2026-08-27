<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $price
 * @property int $display_order
 * @property string|null $condition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'price', 'display_order', 'condition'])]
class TicketType extends Model
{
    protected $table = 'm_ticket_types';

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ReservationSeat, $this>
     */
    public function reservationSeats(): HasMany
    {
        return $this->hasMany(ReservationSeat::class, 'ticket_type_id');
    }
}
