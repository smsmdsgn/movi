<?php

namespace App\Console\Commands;

use App\Services\StampGrant;
use App\Services\StampService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * B-03 スタンプ付与（10章 / 4.5.1）。10分ごとに実行する。
 *
 * **判定と更新は `StampService` が持つ。** コマンドは呼び出しと結果の出力だけを行う
 * （手動実行・テストから同じ処理へ入れるようにするため）。
 */
class GrantStampsCommand extends Command
{
    protected $signature = 'stamps:grant {--limit= : 1回に付与する上限（既定は StampService::PER_RUN_LIMIT）}';

    protected $description = '上映開始を経過した決済済み予約にスタンプを付与し、たまった会員へ無料鑑賞券を発行する';

    public function handle(StampService $stamps): int
    {
        $limit = $this->limit();

        if ($limit === null) {
            $this->error('--limit には1以上の整数を指定してください。');

            return self::INVALID;
        }

        $result = $stamps->grant($limit);

        $this->report($result, $limit);

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 結果を画面とログの双方へ出す。
     *
     * **ログへ落とすのは、スケジュール実行では標準出力がどこにも残らないため**
     * （`schedule:run` 経由の出力は cron 任せになる）。初回の消化中なのか停止して
     * いるのかを、運用側が後から切り分けられるようにする。
     */
    private function report(StampGrant $result, int $limit): void
    {
        $this->info("スタンプを {$result->granted} 個付与し、無料鑑賞券を {$result->issued} 枚発行しました。");

        // **何もしなかった回は残さない。** 10分ごとに動くため、0件の回まで書くと
        // ログが日に百数十行の「何も起きていない」で埋まる（`LOG_DAILY_DAYS`）。
        if ($result->granted > 0 || $result->issued > 0 || $result->failed > 0) {
            Log::info('Stamps granted.', [
                'granted' => $result->granted,
                'issued' => $result->issued,
                'failed' => $result->failed,
            ]);
        }

        // 上限で打ち切った回は次回が続きを拾うため異常ではないが、**初回の消化中で
        // あることが分からないと停止と見分けがつかない**（10章 B-03）。
        if ($result->reachedLimit($limit)) {
            $this->line("上限（{$limit} 件）に達しました。対象が残っていれば次回の実行で付与します。");
        }

        // 交換の失敗は `StampService` がログへ落としている。ここでは数だけを示し、
        // 終了コードを分ける。**`schedule:run` 自体の終了コードは 0 のまま**であり
        // （失敗は `ScheduledTaskFailed` として報告されるだけ）、cron へは伝わらない。
        // 通知を要するようになった時点で `->onFailure()` を足すこと。
        if ($result->failed > 0) {
            $this->error("{$result->failed} 名の無料鑑賞券の発行に失敗しました。ログを確認してください。");
        }
    }

    /**
     * `--limit` の指定。**不正な値は黙って解釈しない**（`--limit=abc` を1件と読むと、
     * 消化が終わらないまま正常に見える）。
     *
     * @return int|null 不正な指定の場合は null
     */
    private function limit(): ?int
    {
        $option = $this->option('limit');

        if ($option === null) {
            return StampService::PER_RUN_LIMIT;
        }

        $limit = filter_var($option, FILTER_VALIDATE_INT);

        return $limit === false || $limit < 1 ? null : $limit;
    }
}
