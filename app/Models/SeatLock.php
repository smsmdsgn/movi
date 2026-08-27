<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $screening_id
 * @property int $seat_id
 * @property string $holder_key
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['screening_id', 'seat_id', 'holder_key', 'expires_at'])]
class SeatLock extends Model
{
    protected $table = 't_seat_locks';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
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
}
