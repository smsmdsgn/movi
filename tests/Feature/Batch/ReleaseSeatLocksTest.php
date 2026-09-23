<?php

use App\Models\SeatLock;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

/*
 * 座席ロックの解放（B-01、10章 / 6.4.1 / 4.3.19）。**期限切れだけを消すこと**と、
 * 上限で分割して消化することを固定する。
 */

/** 指定の期限でロックを1件作る。 */
function seatLockExpiringAt(CarbonImmutable $expiresAt): SeatLock
{
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture(1);

    return SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seats[0]->id,
        'holder_key' => 'session:test-'.uniqid(),
        'expires_at' => $expiresAt,
    ]);
}

it('期限を過ぎたロックを削除する（6.4.1-3）', function () {
    $expired = seatLockExpiringAt(CarbonImmutable::now()->subMinute());

    expect(app(SeatLockService::class)->releaseExpired())->toBe(1)
        ->and(SeatLock::whereKey($expired->id)->exists())->toBeFalse();
});

it('期限内のロックは残す（`SeatLock::active()` の裏返し）', function () {
    $active = seatLockExpiringAt(CarbonImmutable::now()->addMinutes(5));

    expect(app(SeatLockService::class)->releaseExpired())->toBe(0)
        ->and(SeatLock::whereKey($active->id)->exists())->toBeTrue();
});

it('期限ちょうどのロックは削除する（読み取り側が既に無視しているため）', function () {
    $now = CarbonImmutable::now()->startOfSecond();
    CarbonImmutable::setTestNow($now);

    $lock = seatLockExpiringAt($now);

    // `SeatLock::active()` は `expires_at > now` であり、ちょうどは「有効ではない」。
    expect(SeatLock::query()->active()->count())->toBe(0)
        ->and(app(SeatLockService::class)->releaseExpired())->toBe(1)
        ->and(SeatLock::whereKey($lock->id)->exists())->toBeFalse();

    CarbonImmutable::setTestNow();
});

it('上限に達した分は次回の実行で削除する（10章 B-01）', function () {
    foreach (range(1, 3) as $ignored) {
        seatLockExpiringAt(CarbonImmutable::now()->subMinute());
    }

    expect(app(SeatLockService::class)->releaseExpired(limit: 2))->toBe(2)
        ->and(SeatLock::count())->toBe(1)
        ->and(app(SeatLockService::class)->releaseExpired(limit: 2))->toBe(1)
        ->and(SeatLock::count())->toBe(0);
});

it('コマンドから実行できる（10章 B-01）', function () {
    seatLockExpiringAt(CarbonImmutable::now()->subMinute());

    $this->artisan('seat-locks:release')
        ->expectsOutputToContain('期限切れの座席ロックを 1 件削除しました。')
        ->assertSuccessful();

    expect(SeatLock::count())->toBe(0);
});

it('不正な --limit は黙って解釈せず失敗させる', function () {
    seatLockExpiringAt(CarbonImmutable::now()->subMinute());

    $this->artisan('seat-locks:release', ['--limit' => 'abc'])
        ->assertExitCode(Command::INVALID);

    expect(SeatLock::count())->toBe(1);
});

it('10分ごとに実行するようスケジュールへ登録する（10章 B-01）', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'seat-locks:release'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        // 既定（24時間）を使わない（4.5.5 と同じ理由）。
        ->and($event->expiresAt)->toBe(15);
});
