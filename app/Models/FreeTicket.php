<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property int|null $reservation_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'code', 'issued_at', 'expires_at'])]
class FreeTicket extends Model
{
    /**
     * 無料鑑賞券1枚との交換に要するスタンプの数（4.5.1-2）。
     *
     * **表示（マイページ P-05）・付与バッチ（B-03、10章）・シーダーが同じ定数を参照し、
     * 規則を1箇所に持つ**（`Screening::SALES_START_DAYS_BEFORE` と同じ扱い）。
     */
    public const int STAMPS_PER_TICKET = 5;

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
     * **使用状態の判定には使わない。** `reservation_id` / `used_at` は 6.1追記表
     * 「無料鑑賞券の使用状態の管理方式」により廃止予定の列であり、同じ関係を
     * `t_reservations.active_free_ticket_id`（生成列）と双方向に持っていて同期の
     * 保証が無い。判定は `consumedBy()` / `available()` を使うこと。
     *
     * @return BelongsTo<Reservation, $this>
     */
    public function usedInReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    /**
     * この券を使用している予約（6.1追記表「無料鑑賞券の使用状態の管理方式」）。
     *
     * **`t_reservations.active_free_ticket_id` を単一の真実源とする。** 生成列であり、
     * 予約が `paid` のときだけ `free_ticket_id` の値を持つ（6.4.2 の `active_seat_id` と
     * 同じ考え方）。キャンセルで `status` が変われば自動的に外れるため、券を戻す処理を
     * 明示的に書かなくてよい（4.3.18）。
     *
     * @return HasOne<Reservation, $this>
     */
    public function consumedBy(): HasOne
    {
        return $this->hasOne(Reservation::class, 'active_free_ticket_id');
    }

    /**
     * 使える券（未使用かつ有効期限内。4.5.2-4）。
     *
     * 6.1追記表が「個別に一覧表示する画面が必要になった場合は `active_free_ticket_id`
     * へ `LEFT JOIN` し、一致がなければ未使用と判定する」としている導出そのもの。
     * マイページ（P-05、7.14 構成要素1）が最初の読み手になる。
     *
     * @param  Builder<FreeTicket>  $query
     * @return Builder<FreeTicket>
     */
    #[Scope]
    protected function available(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $query
            ->whereDoesntHave('consumedBy')
            ->where('expires_at', '>', $now ?? Date::now());
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
