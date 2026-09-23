<?php

namespace App\Models;

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Date;

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
     * **`pending` は期限内のものに限る**（工程6-d で狭めた。4.3.19）。`expires_at` は
     * 保持していた座席ロックのうち最も早い期限に合わせてあり、それを過ぎた `pending` は
     * **座席を押さえていない**（ロックが切れており、確定時の所有権の再検証も通らない）。
     * B-02（10章）が `expired` へ倒すまでの間も座席を押さえているとみなすと、決済を
     * 中断した利用者が1人いるだけで A-09 がその上映回を編集できない時間が生じる
     * （B-02 が止まれば恒久的に。旧12章 残課題35）。
     *
     * **`expires_at` が null の `pending` は含める。** 確定済み（`paid`）は期限を持たない
     * 一方、`pending` で null になるのは想定していない状態であり、除外すると座席を
     * 押さえたまま A-09 の編集を許すことになる。
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    #[Scope]
    protected function active(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $query->where(function (Builder $query) use ($now): void {
            $query
                ->where('status', ReservationStatus::Paid)
                ->orWhere(function (Builder $query) use ($now): void {
                    $query
                        ->where('status', ReservationStatus::Pending)
                        ->where(function (Builder $query) use ($now): void {
                            $query
                                ->whereNull('expires_at')
                                ->orWhere('expires_at', '>', $now ?? Date::now());
                        });
                });
        });
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
     * 利用者に示してよい予約（`paid` と `cancelled`）。
     *
     * `pending` は課金の直前に作られる行であり（4.3.15）、利用者から見れば成立して
     * いない。`expired` は座席を確保できないまま終わった行で、いずれも示す内容を
     * 持たない。`cancelled` を含めるのは、返金の状況を確認する経路が他に無いためである
     * （4.3.16）。
     *
     * **顧客向けの画面はこのスコープを使い、`whereIn('status', …)` を直接書かない**
     * （4.3.8「条件の集約」。予約照会 P-07・マイページ P-05・予約詳細 P-06 が同じ
     * 条件を持つため、片方だけの改定を許さない）。予約完了（P-38）は `paid` のみを
     * 示すため対象外である（4.3.16）。
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    #[Scope]
    protected function visibleToCustomer(Builder $query): Builder
    {
        return $query->whereIn('status', [ReservationStatus::Paid, ReservationStatus::Cancelled]);
    }

    /**
     * 座席表（P-31）と同じ並びの予約座席（7.19-4）。
     *
     * **読み込み済みの関連を並べ替えるだけで、追加のクエリを出さない。** 明細を出す
     * 画面（P-07 / P-06 / P-38）がそれぞれ同じ並べ替えを書かないよう、モデルへ寄せる
     * （4.3.8「条件の集約」）。呼び出し側は `seats.seat` を読み込んでおくこと。
     *
     * @return Collection<int, ReservationSeat>
     */
    public function seatsInGridOrder(): Collection
    {
        return $this->seats
            ->sortBy(fn (ReservationSeat $row): array => [$row->seat->grid_row, $row->seat->grid_col])
            ->values();
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
