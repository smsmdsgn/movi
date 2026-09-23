<?php

namespace App\Console\Commands;

use App\Services\SeatLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * B-01 座席ロックの解放（10章 / 6.4.1）。10分ごとに実行する。
 *
 * **削除は `SeatLockService` に集約する**（13.4.6。`t_seat_locks` への直接操作を
 * 各所に書かない）。
 */
class ReleaseSeatLocksCommand extends Command
{
    protected $signature = 'seat-locks:release {--limit= : 1回に削除する上限（既定は SeatLockService::RELEASE_PER_RUN_LIMIT）}';

    protected $description = '有効期限を経過した座席ロックを削除する';

    public function handle(SeatLockService $locks): int
    {
        $limit = $this->limit();

        if ($limit === null) {
            $this->error('--limit には1以上の整数を指定してください。');

            return self::INVALID;
        }

        $released = $locks->releaseExpired($limit);

        $this->info("期限切れの座席ロックを {$released} 件削除しました。");

        // 何もしなかった回は残さない（`stamps:grant` と同じ扱い。4.5.5）。
        if ($released > 0) {
            Log::info('Expired seat locks released.', ['released' => $released]);
        }

        if ($released >= $limit) {
            $this->line("上限（{$limit} 件）に達しました。残りがあれば次回の実行で削除します。");
        }

        return self::SUCCESS;
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
            return SeatLockService::RELEASE_PER_RUN_LIMIT;
        }

        $limit = filter_var($option, FILTER_VALIDATE_INT);

        return $limit === false || $limit < 1 ? null : $limit;
    }
}
