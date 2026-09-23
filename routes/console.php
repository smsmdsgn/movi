<?php

use App\Console\Commands\ExpirePendingReservationsCommand;
use App\Console\Commands\GrantStampsCommand;
use App\Console\Commands\ReleaseSeatLocksCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| バッチ処理（10章）
|--------------------------------------------------------------------------
|
| Cron が `schedule:run` を毎分実行する。**常駐プロセスは使えない**ため、非同期の
| 処理はすべてここを経由する（3.4）。
|
*/

// B-01 座席ロックの解放（6.4.1 / 4.3.19）。
//
// 期限切れのロックは読み取りの側で既に無視されている（`SeatLock::active()`）ため、
// **遅れても販売には影響しない**。重ならないようにするのは、同じ行への `DELETE` を
// 二重に走らせても得るものが無いためである。
Schedule::command(ReleaseSeatLocksCommand::class)
    ->everyTenMinutes()
    ->withoutOverlapping(15);

// B-02 未決済予約の無効化（4.3.3 / 4.3.19）。
//
// **1件ごとに Stripe へ問い合わせうる**ため、重なりを避ける意味が B-01 より大きい
// （同じ PaymentIntent に二重に `cancel` を投げない）。
Schedule::command(ExpirePendingReservationsCommand::class)
    ->everyTenMinutes()
    ->withoutOverlapping(15);

// B-03 スタンプ付与（4.5.1 / 4.5.5）。
//
// `withoutOverlapping()` を付けるのは、初回の実行が過去分を消化している間（上限
// 1000件で打ち切るため複数回に分かれる）に次の実行が重なると、同じ予約を二重に
// 読んで無駄な一意制約違反を量産するため。**二重付与そのものは制約とロックが
// 止める**（4.5.5）が、重ねて得るものが無い。
//
// **ロックの期限を明示する（既定の24時間を使わない）。** ロックは実行後のコールバック
// で解放されるため、実行時間の超過などでプロセスが強制終了されると残り続ける。既定の
// ままだと、その1回の巻き添えで**丸1日スタンプが付かない**（気づく契機も無い）。
// 実行間隔（10分）より長く、復帰が遅れない長さとして15分を置く。
Schedule::command(GrantStampsCommand::class)
    ->everyTenMinutes()
    ->withoutOverlapping(15);
