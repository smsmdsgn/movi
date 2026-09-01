<?php

namespace Database\Seeders\Concerns;

/**
 * シーダーで使う決定的な擬似乱数（crc32ベース）。同じ種であれば再実行しても同じ値になる。
 * crc32は32bit環境で負値になりうるため `& 0x7FFFFFFF` でマスクする。
 */
trait DeterministicRandom
{
    /**
     * 種から 0〜99 の決定的な値を求める。
     */
    private function deterministicRatio(string $seed): int
    {
        return (crc32($seed) & 0x7FFFFFFF) % 100;
    }

    /**
     * 種から 0〜($count-1) の決定的な値を求める。
     */
    private function deterministicIndex(string $seed, int $count): int
    {
        return (crc32($seed) & 0x7FFFFFFF) % $count;
    }
}
