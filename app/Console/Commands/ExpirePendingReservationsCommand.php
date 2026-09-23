<?php

namespace App\Console\Commands;

use App\Services\PendingExpiry;
use App\Services\ReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * B-02 未決済予約の無効化（10章 / 4.3.19）。10分ごとに実行する。
 *
 * **判定と更新は `ReservationService` が持つ**（金銭に触れる経路を1箇所に閉じる。
 * 13.4.7 / 4.3.18）。コマンドは呼び出しと結果の出力だけを行う。
 */
class ExpirePendingReservationsCommand extends Command
{
    protected $signature = 'reservations:expire {--limit= : 1回に処理する上限（既定は ReservationService::EXPIRE_PER_RUN_LIMIT）}';

    protected $description = '座席ロックの期限を過ぎた未決済（pending）の予約を無効化する';

    public function handle(ReservationService $reservations): int
    {
        $limit = $this->limit();

        if ($limit === null) {
            $this->error('--limit には1以上の整数を指定してください。');

            return self::INVALID;
        }

        $result = $reservations->expirePending($limit);

        $this->report($result, $limit);

        // **課金が残っている行（`withCharge`）では失敗としない。** 倒さないことが
        // 正しい動作であり、毎回 `schedule:run` の失敗として報告すると、書き込みに
        // 失敗している状態と区別できなくなる（4.3.19）。
        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function report(PendingExpiry $result, int $limit): void
    {
        $this->info("未決済の予約を {$result->expired} 件無効化しました。");

        if ($result->expired > 0 || $result->withCharge > 0 || $result->failed > 0) {
            Log::info('Pending reservations expired.', [
                'expired' => $result->expired,
                'with_charge' => $result->withCharge,
                'failed' => $result->failed,
            ]);
        }

        if ($result->failed > 0) {
            $this->error("{$result->failed} 件の無効化に失敗しました。ログを確認してください。");
        }

        // **課金が残っている予約は異常である**（確定できなかったのに返金もできていない。
        // 17.3-5 / 12章 残課題39）。倒さずに残すのは手がかりを消さないためであり、
        // 件数を出さないと「対象が無い」のか「見送り続けている」のか分からない。
        if ($result->withCharge > 0) {
            $this->warn("{$result->withCharge} 件は課金が残っているため無効化していません。Stripe 側の状態を確認してください。");
        }

        if ($result->reachedLimit($limit)) {
            $this->line("上限（{$limit} 件）に達しました。対象が残っていれば次回の実行で処理します。");
        }
    }

    /**
     * `--limit` の指定。**不正な値は黙って解釈しない**（`stamps:grant` と同じ扱い）。
     *
     * @return int|null 不正な指定の場合は null
     */
    private function limit(): ?int
    {
        $option = $this->option('limit');

        if ($option === null) {
            return ReservationService::EXPIRE_PER_RUN_LIMIT;
        }

        $limit = filter_var($option, FILTER_VALIDATE_INT);

        return $limit === false || $limit < 1 ? null : $limit;
    }
}
