<?php

namespace App\Services;

use App\Models\Format;
use App\Models\Movie;
use Illuminate\Support\Collection;

/**
 * 上映スケジュール表（7.4）の作品ブロック1件分。作品ごとにブロックを構成し、
 * その中に当日の上映回（`ScheduleSlot`）を開始時刻順で並べる（4.2.2-2）。
 */
final readonly class ScheduleBlock
{
    /**
     * @param  Collection<int, Format>  $formats  当日この作品に付く上映規格（重複なし）
     * @param  Collection<int, ScheduleSlot>  $slots  開始時刻の昇順
     */
    public function __construct(
        public Movie $movie,
        public Collection $formats,
        public Collection $slots,
    ) {}

    /**
     * 同じ作品が複数の規格で上映される日は、ボタン側にも規格名を出して区別できるようにする。
     */
    public function hasMultipleFormats(): bool
    {
        return $this->formats->count() > 1;
    }
}
