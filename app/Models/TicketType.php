<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $price
 * @property int $display_order
 * @property string|null $condition
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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
