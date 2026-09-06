<?php

namespace App\Services;

use App\Enums\SeatAvailability;
use App\Models\Screening;

/**
 * 上映スケジュール表（7.4）の上映回1件分の表示データ。
 * `ScheduleService` が空席状況を一括集計して組み立てる（5.3-1）。
 * 残席数そのものは表示しない（4.2.2-3）ため、判定結果の `SeatAvailability` のみを持つ。
 *
 * `$screening` は `booking.movie` / `booking.format` / `theater` を先読み済みであること。
 */
final readonly class ScheduleSlot
{
    public function __construct(
        public Screening $screening,
        public SeatAvailability $availability,
    ) {}
}
