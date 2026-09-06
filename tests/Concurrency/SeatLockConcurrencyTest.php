<?php

use App\Models\Seat;
use App\Models\SeatLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * T-01（13.6）: 同一座席に対する同時ロック取得で1件のみ成功すること。
 *
 * 本ディレクトリは `RefreshDatabase` ではなく `DatabaseTruncation` を用いる
 * （`tests/Pest.php`）。`RefreshDatabase` はテストをトランザクションで包むため、
 * 用意したデータが子プロセスの別コネクションから見えず、同時実行を再現できない
 * （13.6「実行環境」の注記）。
 *
 * 取得は子プロセスで行う。同一プロセス内の複数コネクションでは、一方が行ロックで
 * ブロックされた時点でテスト自身が停止し、競合を解けないため。
 */
$competitorCount = 4;

/* テストごとの後始末（データを残さない）は tests/Pest.php がディレクトリ全体に掛けている。 */

it('同一座席への同時ロック取得は1件のみ成功する（T-01 / 6.4.1-1）', function () use ($competitorCount) {
    $theater = createTheater();
    $seatType = makeSeatsWithSurcharge($theater, 1, 0);
    $seat = Seat::where('seat_type_id', $seatType->id)->sole();

    [$screening] = makeScreenings($theater, [CarbonImmutable::now()->addDay()->setTime(10, 0)]);

    ['results' => $results, 'spread' => $spread] = runConcurrentAcquisitions($screening->id, $seat->id, $competitorCount);

    expect(array_count_values($results)['1'] ?? 0)->toBe(1);
    expect(SeatLock::where('screening_id', $screening->id)->where('seat_id', $seat->id)->count())->toBe(1);

    assertActuallyRaced($spread);
});

it('同時取得の敗者は座席の保持者を書き換えない（17.8-4）', function () use ($competitorCount) {
    $theater = createTheater();
    $seatType = makeSeatsWithSurcharge($theater, 1, 0);
    $seat = Seat::where('seat_type_id', $seatType->id)->sole();

    [$screening] = makeScreenings($theater, [CarbonImmutable::now()->addDay()->setTime(10, 0)]);

    ['results' => $results, 'spread' => $spread] = runConcurrentAcquisitions($screening->id, $seat->id, $competitorCount);

    $winners = array_keys(array_filter($results, fn (string $result): bool => $result === '1'));

    expect($winners)->toHaveCount(1);
    expect(SeatLock::where('seat_id', $seat->id)->sole()->holder_key)->toBe($winners[0]);

    assertActuallyRaced($spread);
});

/**
 * 各プロセスが実際に競合したことを確かめる。子プロセスの起動が同期バリアに間に合わないと
 * 逐次実行に退化し、「成功は1件」が競合と無関係に成立してしまう（テストは通るが T-01 を
 * 検証していない状態になる）。
 *
 * **不変条件の検証を済ませてから呼ぶこと。** `markTestIncomplete()` は例外を投げるため、
 * 先に呼ぶと「再現の有無と無関係に成立すべき検証」まで捨ててしまう。
 */
function assertActuallyRaced(float $spread): void
{
    if ($spread > 0.5) {
        test()->markTestIncomplete(
            "子プロセスの取得開始が {$spread} 秒ずれており、同時実行を再現できていません。"
        );
    }
}

/**
 * 保持者キーの異なる子プロセスを同時に起動し、取得結果（保持者キー => `1`（成功）または
 * `0`（失敗））と、取得を試みた時刻のばらつき（秒）を返す。
 *
 * @return array{results: array<string, string>, spread: float}
 */
function runConcurrentAcquisitions(int $screeningId, int $seatId, int $count): array
{
    /*
     * 本スイートがトランザクションの内側で走っていないことを確かめる。開いていると
     * 用意したデータが子プロセスから見えないうえ、挿入時の行ロックを親が握ったままに
     * なり、子プロセスがロック待ちで停止する（13.6「実行環境」）。
     */
    expect(DB::transactionLevel())->toBe(0, '本スイートは DatabaseTruncation で実行すること（tests/Pest.php）。');

    $script = base_path('tests/Concurrency/support/acquire-seat-lock.php');

    // 全プロセスの起動を待ってから一斉に取得させる。子プロセスの初期化（フレームワークの
    // 起動）に要する時間を見込んで余裕を持たせる。
    $startAt = microtime(true) + 3.0;

    $environment = [
        'DB_CONNECTION' => config('database.default'),
        'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
        'DB_URL' => '',
        // 子プロセスは親の環境変数を継承するが、接続先とタイムゾーンは明示的に渡す。
        // 13.3 の「日時は Asia/Tokyo で保存」を、コミットされない .env 任せにしない。
        'APP_TIMEZONE' => config('app.timezone'),
    ];

    $running = [];

    foreach (range(1, $count) as $index) {
        $holderKey = "session:competitor-{$index}";

        // PHP_BINARY を使う。PATH 上の `php` はテストを実行している処理系と異なりうる（16.6）。
        $running[$holderKey] = Process::env($environment)
            ->timeout(60)
            ->start([PHP_BINARY, $script, (string) $screeningId, (string) $seatId, $holderKey, (string) $startAt]);
    }

    $results = [];
    $attemptedAt = [];

    foreach ($running as $holderKey => $process) {
        $result = $process->wait();

        expect($result->successful())
            ->toBeTrue("子プロセスが異常終了しました: {$result->errorOutput()}");

        // 出力は「取得結果(1桁) 半角空白 取得を試みた時刻」
        [$acquired, $startedAt] = explode(' ', trim($result->output()));

        $results[$holderKey] = $acquired;
        $attemptedAt[$holderKey] = (float) $startedAt;
    }

    return [
        'results' => $results,
        'spread' => max($attemptedAt) - min($attemptedAt),
    ];
}
