<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $surcharge
 * @property string $display_class
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'surcharge', 'display_class'])]
class SeatType extends Model
{
    protected $table = 'm_seat_types';

    protected function casts(): array
    {
        return [
            'surcharge' => 'integer',
        ];
    }

    /**
     * @return HasMany<Seat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class, 'seat_type_id');
    }
}
