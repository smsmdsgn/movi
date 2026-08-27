<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $reservation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'code', 'issued_at', 'expires_at'])]
class FreeTicket extends Model
{
    protected $table = 't_free_tickets';

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 使用済みとなったリンク先の予約（未使用の場合は null）。
     *
     * @return BelongsTo<Reservation, $this>
     */
    public function usedInReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    /**
     * この券への交換に使用されたスタンプ。
     *
     * @return HasMany<Stamp, $this>
     */
    public function stamps(): HasMany
    {
        return $this->hasMany(Stamp::class, 'free_ticket_id');
    }
}
