<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property int $screening_id
 * @property int $seat_id
 * @property string $holder_key
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['screening_id', 'seat_id', 'holder_key', 'expires_at'])]
class SeatLock extends Model
{
    protected $table = 't_seat_locks';

    protected function casts(): array
    {
        return [
            // 保持座席の判定（`SeatLockService::withinHolderLimit()`）が厳密比較を行うため、
            // PDO の返す型に依存しないよう明示的にキャストする。
            'screening_id' => 'integer',
            'seat_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * 有効期限が未経過のロック（6.4.1）。残席の集計（7.4）が参照する。
     * `SeatLockService`（13.4.6）実装時も同じ条件を本スコープに寄せること。
     *
     * @param  Builder<SeatLock>  $query
     * @return Builder<SeatLock>
     */
    #[Scope]
    protected function active(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $query->where('expires_at', '>', $now ?? Date::now());
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
