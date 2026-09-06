<?php

/**
 * T-01（13.6「座席の二重予約防止」）の検証用に、`SeatLockService::acquire()` を
 * **独立したプロセス・独立したDBコネクション**で1回だけ実行する。
 *
 * `tests/Concurrency/SeatLockConcurrencyTest.php` から複数同時に起動される。
 * テスト本体と同一プロセスでは複数コネクションの同時実行を再現できないため
 * （13.6「実行環境」の注記）、子プロセスとして分離している。
 *
 * 引数: {上映回ID} {座席ID} {保持者キー} {開始時刻（microtime(true) 形式）}
 * 標準出力: `{取得結果} {取得を試みた時刻}`。取得結果は成功が `1`、失敗が `0`。
 * 時刻は呼び出し側が「各プロセスが実際に競合したか」を判定するために使う。
 *
 * 接続先データベースは呼び出し側が環境変数で渡す（テスト用の `movi_testing`）。
 * Laravel の Dotenv は既存の環境変数を上書きしないため、`.env` より優先される。
 */

use App\Models\Screening;
use App\Models\Seat;
use App\Services\SeatLockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 3);

require $basePath.'/vendor/autoload.php';

/** @var Application $app */
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/*
 * 接続先がテスト用データベースであることを確かめる。`bootstrap/cache/config.php` が
 * 存在する環境では設定キャッシュが優先され、渡した `DB_DATABASE` が無視される。
 * 開発用データベースにも同じ id の上映回・座席が実在しうるため、気付かないまま
 * そちらへロック行を書き込む経路を塞ぐ。
 */
$expectedDatabase = getenv('DB_DATABASE');
$actualDatabase = DB::connection()->getDatabaseName();

if ($expectedDatabase !== false && $actualDatabase !== $expectedDatabase) {
    fwrite(STDERR, "接続先が想定と異なります（期待: {$expectedDatabase} / 実際: {$actualDatabase}）。");
    exit(1);
}

[$screeningId, $seatId, $holderKey, $startAt] = array_slice($argv, 1, 4);

// 全プロセスが同じ時刻に取得を試みるまで待つ。取得の重なりを最大化するためのもので、
// 実際に重ならなかった場合でも「成功は1件のみ」という検証は成立する。
$startAt = (float) $startAt;

while (microtime(true) < $startAt) {
    usleep(200);
}

$screening = Screening::findOrFail((int) $screeningId);
$seat = Seat::findOrFail((int) $seatId);

$attemptedAt = microtime(true);
$acquired = $app->make(SeatLockService::class)->acquire($screening, $seat, $holderKey);

echo ($acquired ? '1' : '0').' '.$attemptedAt;
