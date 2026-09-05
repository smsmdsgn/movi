<?php

namespace App\Models;

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $reservation_no
 * @property int|null $user_id
 * @property string|null $guest_name
 * @property string|null $guest_name_kana
 * @property ContactType $contact_type
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property int $screening_id
 * @property ReservationStatus $status
 * @property int $total_amount
 * @property int|null $free_ticket_id
 * @property string|null $entry_code
 * @property string|null $stripe_payment_intent_id
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $checked_in_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $active_free_ticket_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'reservation_no', 'user_id', 'guest_name', 'guest_name_kana', 'contact_type',
    'guest_email', 'guest_phone', 'screening_id', 'status', 'total_amount',
    'free_ticket_id', 'entry_code', 'expires_at',
])]
#[Hidden(['stripe_payment_intent_id'])]
class Reservation extends Model
{
    protected $table = 't_reservations';

    protected function casts(): array
    {
        return [
            'contact_type' => ContactType::class,
            'status' => ReservationStatus::class,
            'total_amount' => 'integer',
            'refunded_at' => 'datetime',
            'expires_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'active_free_ticket_id' => 'integer',
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
     * @return BelongsTo<Screening, $this>
     */
    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    /**
     * @return BelongsTo<FreeTicket, $this>
     */
    public function freeTicket(): BelongsTo
    {
        return $this->belongsTo(FreeTicket::class, 'free_ticket_id');
    }

    /**
     * 予約者名。会員は `users.name`、非会員は `guest_name` を用いる（4.8.5）。
     *
     * **メールアドレスはこのメソッドでも画面でも扱わない**（4.8.5: 予約状況・
     * 予約検索の表示範囲はメールアドレスおよび決済情報を含まない）。
     * `user` を参照するため、呼び出し側で eager load すること
     * （`Model::preventLazyLoading` が有効）。
     */
    public function displayName(): string
    {
        return $this->contact_type === ContactType::Member
            ? (string) $this->user?->name
            : (string) $this->guest_name;
    }

    /**
     * 入場済みか（4.6）。入場は予約単位で記録する（`checked_in_at`）。
     */
    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * @return HasMany<ReservationSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(ReservationSeat::class, 'reservation_id');
    }

    /**
     * @return HasOne<Stamp, $this>
     */
    public function stamp(): HasOne
    {
        return $this->hasOne(Stamp::class, 'reservation_id');
    }
}
