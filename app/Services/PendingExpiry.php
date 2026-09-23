<?php

namespace App\Services;

/**
 * `ReservationService::expirePending()` の1回の実行の結果（B-02、10章 / 4.3.19）。
 *
 * **見送った件数を分けて数える。** 課金が残っている予約（Stripe 上で成立したまま
 * 確定できなかったもの）は倒さずに残すが、**それは異常であり運用で追う対象である**
 * （17.3-5 / 12章 残課題39）。件数を返さないと、無効化が進んでいないのか対象が
 * 無いのかを呼び出し側が区別できない。
 */
final readonly class PendingExpiry
{
    public function __construct(
        /** `expired` へ倒した件数。 */
        public int $expired,
        /** 課金が残っている（または確かめられなかった）ため見送った件数。 */
        public int $withCharge,
        /**
         * 処理中に失敗した件数（4.3.19）。
         *
         * 倒すべきだが書き込めなかった行であり、**次回の実行が拾い直す**。続くようなら
         * 原因を追う必要があるため、呼び出し側が数を出せるように持つ。
         */
        public int $failed = 0,
    ) {}

    /**
     * 上限まで処理したか（10章 B-02）。
     *
     * 真であれば**まだ対象が残っている可能性がある**。次回の実行が続きを拾う。
     *
     * **結末の分かった件数で数える**（倒せた件数だけでは、見送りと失敗が続いた回を
     * 「達していない」と報告してしまう）。確定との競合で見送った分（`expireOne()` が
     * false を返した候補）はどれにも入らないため、**上限ちょうどを読んだ回を
     * 「達していない」と報告することはありうる**。案内の文言にしか影響しない。
     */
    public function reachedLimit(int $limit): bool
    {
        return $this->expired + $this->withCharge + $this->failed >= $limit;
    }
}
