<?php

namespace App\Services;

/**
 * `StampService::grant()` の1回の実行の結果（4.5.1 / 10章 B-03）。
 *
 * **付与・発行・失敗を分けて数える。** 1回の実行で「スタンプを何個付けたか」と
 * 「無料鑑賞券を何枚発行したか」は連動せず（規定数に達した会員の分だけ発行される）、
 * 片方だけを返すと実行の結果を読めない。
 */
final readonly class StampGrant
{
    public function __construct(
        /** 付与したスタンプの数（4.5.1-1）。 */
        public int $granted,
        /** 交換により発行した無料鑑賞券の枚数（4.5.1-2）。 */
        public int $issued,
        /**
         * 交換に失敗した会員の数（4.5.5）。
         *
         * **付与は済んでいる。** 失敗した会員は次回の実行が拾い直すが、続くようなら
         * 原因を追う必要があるため、呼び出し側が数を出せるように持つ。
         */
        public int $failed = 0,
    ) {}

    /**
     * 上限まで付与したか（10章 B-03）。
     *
     * 真であれば**まだ対象が残っている可能性がある**（ちょうど上限で尽きた場合も真に
     * なる）。次回の実行が続きを拾う。
     */
    public function reachedLimit(int $limit): bool
    {
        return $this->granted >= $limit;
    }
}
