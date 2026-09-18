<?php

namespace App\Models;

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
     * 上映回の座席を押さえている予約（`pending` / `paid`、4.3.3）。
     *
     * `expired` / `cancelled` は終端であり、行は残るが座席を占有しない
     * （`t_reservation_seats.released_at` が入る。6.4.2）。**「予約が存在するか」を
     * 状態を問わずに判定すると、決済を中断した利用者が1人でもいた上映回を
     * A-09 が恒久的に編集できなくなる**（6.2 制約1 / 12章 旧残課題33。4.3.16）。
     *
     * **`expires_at` は見ない。** 期限を過ぎた `pending` も、B-02（10章）が `expired`
     * へ移すまでは含まれる（座席ロックは切れていても行の状態は `pending` のままである）。
     * 12章 残課題35 の経路では恒久的に残りうる。B-02 の実装時に、本スコープを
     * 「`pending` は期限内のものに限る」へ狭めるかを判断すること。
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Paid]);
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
     * 予約番号の表示形式（4.3.5）。8桁を4桁ずつハイフンで区切る（例: `1234-5678`）。
     * 入力側はハイフンの有無を問わず受け付けるため、保存値は数字のみである。
     */
    public function formattedReservationNo(): string
    {
        return implode('-', str_split($this->reservation_no, 4));
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
