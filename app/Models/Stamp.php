<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $reservation_id
 * @property int|null $free_ticket_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'reservation_id', 'free_ticket_id'])]
class Stamp extends Model
{
    protected $table = 't_stamps';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    /**
     * 無料鑑賞券への交換で消費済みの場合の交換先（未消費の場合は null）。
     *
     * @return BelongsTo<FreeTicket, $this>
     */
    public function freeTicket(): BelongsTo
    {
        return $this->belongsTo(FreeTicket::class, 'free_ticket_id');
    }
}
