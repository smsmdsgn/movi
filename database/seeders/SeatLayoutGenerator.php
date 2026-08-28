<?php

namespace Database\Seeders;

use App\Models\Seat;
use App\Models\SeatType;
use App\Models\Theater;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * シアター1件分の座席レコードを生成する（docs/design.md 6.3.1 / 6.3.3）。
 *
 * 行の構成（前方から）:
 * 1. 最前列: 左右1列ずつ減らした縮小幅（規則1）
 * 2. 横通路より前の中間行: 全幅（M以上の規模のみ存在。横通路の分だけ grid_row を1つ空ける）
 * 3. 横通路より後ろの行（最後列を除く）: 全幅のまま中央2列を通路として空け、左右に分割する（規則3）。
 *    横通路がない規模（XS/S）では最前列の次からすべてこの分割行になる
 * 4. 最後列: 全幅・分割なし。両端2席ずつが車椅子、L以上の規模では中央8席がエグゼクティブ（規則5）
 *
 * 6.3.3の「行×列」は行数×列数の単純な積であり目安値。実際に生成される座席数は
 * 通路・縮小分だけ少なくなる（6.3.3「実座席数」列参照）。
 */
class SeatLayoutGenerator
{
    public static function generate(Theater $theater, int $rows, int $cols, bool $hasAisle, bool $hasExecutive): void
    {
        if ($theater->seats()->exists()) {
            return;
        }

        $seatTypeIds = SeatType::pluck('id', 'name');
        $frontRowsBeforeAisle = $hasAisle ? intdiv($rows, 2) : 0;
        $now = now();

        $seats = [];

        for ($row = 1; $row <= $rows; $row++) {
            $gridRow = $row + ($hasAisle && $row > $frontRowsBeforeAisle ? 1 : 0);

            $isFront = $row === 1;
            $isLast = $row === $rows;
            $isSplit = ! $isFront && ! $isLast && (! $hasAisle || $row >= $frontRowsBeforeAisle + 1);

            $rowLabel = self::rowLabel($row);

            if ($isLast) {
                $seats = [...$seats, ...self::lastRowSeats($theater, $rowLabel, $gridRow, $cols, $hasExecutive, $seatTypeIds, $now)];

                continue;
            }

            $columns = match (true) {
                $isFront => self::narrowedColumns($cols),
                $isSplit => self::splitColumns($cols),
                default => range(1, $cols),
            };

            foreach (array_values($columns) as $position => $gridCol) {
                $seats[] = self::seatRow($theater, $rowLabel, $position + 1, $gridRow, $gridCol, $seatTypeIds[SeedConfig::SEAT_TYPE_GENERAL], $now);
            }
        }

        DB::transaction(function () use ($seats) {
            collect($seats)->chunk(500)->each(fn ($chunk) => Seat::query()->insert($chunk->all()));
        });
    }

    /**
     * @return array<int, int>
     */
    private static function narrowedColumns(int $cols): array
    {
        return range(2, $cols - 1);
    }

    /**
     * @return array<int, int>
     */
    private static function splitColumns(int $cols): array
    {
        $leftCount = intdiv($cols - 2, 2);
        $rightStart = $leftCount + 3;

        return [...range(1, $leftCount), ...range($rightStart, $cols)];
    }

    /**
     * @param  Collection<string, int>  $seatTypeIds
     * @return array<int, array<string, mixed>>
     */
    private static function lastRowSeats(Theater $theater, string $rowLabel, int $gridRow, int $cols, bool $hasExecutive, Collection $seatTypeIds, CarbonImmutable $now): array
    {
        $execStart = $hasExecutive ? intdiv($cols, 2) - 3 : null;
        $execEnd = $hasExecutive ? $execStart + 7 : null;

        $seats = [];
        foreach (range(1, $cols) as $position => $gridCol) {
            $seatTypeId = match (true) {
                $gridCol <= 2 || $gridCol > $cols - 2 => $seatTypeIds[SeedConfig::SEAT_TYPE_WHEELCHAIR],
                $hasExecutive && $gridCol >= $execStart && $gridCol <= $execEnd => $seatTypeIds[SeedConfig::SEAT_TYPE_EXECUTIVE],
                default => $seatTypeIds[SeedConfig::SEAT_TYPE_GENERAL],
            };

            $seats[] = self::seatRow($theater, $rowLabel, $position + 1, $gridRow, $gridCol, $seatTypeId, $now);
        }

        return $seats;
    }

    /**
     * @return array<string, mixed>
     */
    private static function seatRow(Theater $theater, string $rowLabel, int $seatNumber, int $gridRow, int $gridCol, int $seatTypeId, CarbonImmutable $now): array
    {
        return [
            'theater_id' => $theater->id,
            'seat_type_id' => $seatTypeId,
            'row_label' => $rowLabel,
            'seat_number' => str_pad((string) $seatNumber, 2, '0', STR_PAD_LEFT),
            'grid_row' => $gridRow,
            'grid_col' => $gridCol,
            'is_available' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * 27行目以降は同一文字を重ねる（docs/design.md 6.3.1 採番規則）。
     * 53行目以降は文字が一巡し重複するが、最大規模（XL, 15行）では到達しない。
     */
    private static function rowLabel(int $row): string
    {
        if ($row <= 26) {
            return chr(64 + $row);
        }

        $letter = chr(64 + ((($row - 27) % 26) + 1));

        return $letter.$letter;
    }
}
