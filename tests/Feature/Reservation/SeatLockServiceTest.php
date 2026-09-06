<?php

use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Theater;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;

/**
 * `SeatLockService`（13.4.6）の取得条件を固定する。同時取得で1件のみ成功すること
 * （13.6 T-01）は複数コネクションを要するため `tests/Concurrency/` で検証する。
 */

/**
 * 販売期間内（4.3.1）の上映回と、その回のシアターに属する座席を作る。
 *
 * `preventLazyLoading`（本番以外で有効）のため、シアターは関連の遅延ロードではなく
 * 本フィクスチャの戻り値から受け取る。
 *
 * @return array{screening: Screening, seats: array<int, Seat>, theater: Theater}
 */
function makeLockFixture(int $seatCount = 3): array
{
    $theater = createTheater();
    $seatType = makeSeatsWithSurcharge($theater, $seatCount, 0);
    $seats = Seat::where('seat_type_id', $seatType->id)->orderBy('id')->get()->all();

    [$screening] = makeScreenings($theater, [CarbonImmutable::now()->addDay()->setTime(10, 0)]);

    return ['screening' => $screening, 'seats' => $seats, 'theater' => $theater];
}

/**
 * 同一シアターに、販売期間内の別の上映回を作る（4.3.4「別の上映回」の検証用）。
 */
function makeOtherScreening(Theater $theater): Screening
{
    $other = createScreeningForTheater($theater);
    $other->update([
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(15, 0),
        'ends_at' => CarbonImmutable::now()->addDay()->setTime(17, 0),
    ]);

    return $other;
}

function lockService(): SeatLockService
{
    return app(SeatLockService::class);
}

it('販売期間内の空席をロックできる', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeTrue();

    $lock = SeatLock::where('screening_id', $screening->id)->where('seat_id', $seats[0]->id)->sole();

    expect($lock->holder_key)->toBe('session:alice');
    expect($lock->expires_at->isFuture())->toBeTrue();
});

it('他者が保持している座席はロックできず、保持者が変わらない（6.4.1-1）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeTrue();
    expect(lockService()->acquire($screening, $seats[0], 'session:bob'))->toBeFalse();

    expect(SeatLock::where('seat_id', $seats[0]->id)->sole()->holder_key)->toBe('session:alice');
});

it('自分が保持している座席の再取得は成功し、有効期限を引き直す', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    $this->travelTo(CarbonImmutable::now()->setTime(10, 0));
    lockService()->acquire($screening, $seats[0], 'session:alice');

    $this->travelTo(CarbonImmutable::now()->addMinutes(5));
    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeTrue();

    $lock = SeatLock::where('seat_id', $seats[0]->id)->sole();

    expect(SeatLock::where('seat_id', $seats[0]->id)->count())->toBe(1);
    expect($lock->expires_at->toDateTimeString())
        ->toBe(CarbonImmutable::now()->addMinutes(SeatLockService::LOCK_MINUTES)->toDateTimeString());
});

it('期限切れのロックが残っている座席は他者が奪取できる（6.4.1-3。B-01の実行を待たない）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seats[0]->id,
        'holder_key' => 'session:alice',
        'expires_at' => CarbonImmutable::now()->subMinute(),
    ]);

    expect(lockService()->acquire($screening, $seats[0], 'session:bob'))->toBeTrue();

    $lock = SeatLock::where('seat_id', $seats[0]->id)->sole();
    expect($lock->holder_key)->toBe('session:bob');
    expect($lock->expires_at->isFuture())->toBeTrue();
});

it('同じ秒に同一座席を再取得しても成功する（座席表のダブルクリック・再送、4.3.8）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    // 時刻を進めない。expires_at が秒精度のため、影響行数で成否を判定すると失敗する。
    $this->travelTo(CarbonImmutable::now()->setTime(10, 0));

    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeTrue();
    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeTrue();

    expect(SeatLock::where('seat_id', $seats[0]->id)->count())->toBe(1);
});

it('決済済みの座席はロックできない（6.4.1-6 / 6.4.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    // 確定済みの予約は座席ロックを持たない（確定時に削除される）。ユニーク制約では防げない。
    createReservationSeat($screening->id, $seats[0]->id, createTicketType()->id);

    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeFalse();
    expect(SeatLock::count())->toBe(0);
});

it('キャンセルにより解放された座席は再びロックできる（6.4.2 / T-04と同趣旨）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    $reservationSeat = createReservationSeat($screening->id, $seats[0]->id, createTicketType()->id);
    $reservationSeat->released_at = CarbonImmutable::now();
    $reservationSeat->save();

    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeTrue();
});

it('使用不可の座席はロックできない（6.2 制約2 / 4.3.8）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    $seats[0]->update(['is_available' => false]);

    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeFalse();
    expect(SeatLock::count())->toBe(0);
});

it('削除済みの上映回ではロックできない（6.2 制約1 / 4.3.8）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    $detached = clone $screening;
    $screening->delete();

    expect(lockService()->acquire($detached, $seats[0], 'session:alice'))->toBeFalse();
});

it('他シアターの座席はロックできない（13.4.7と同型）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeLockFixture();

    $otherTheater = Theater::create([
        'cinema_id' => $theater->cinema_id,
        'number' => 9,
        'name' => '9番シアター',
    ]);
    $otherSeatType = makeSeatsWithSurcharge($otherTheater, 1, 0);
    $otherSeat = Seat::where('seat_type_id', $otherSeatType->id)->sole();

    expect(lockService()->acquire($screening, $otherSeat, 'session:alice'))->toBeFalse();
});

it('販売期間外の上映回ではロックできない（4.3.1）', function (string $travelTo) {
    $theater = createTheater();
    $seatType = makeSeatsWithSurcharge($theater, 1, 0);
    $seat = Seat::where('seat_type_id', $seatType->id)->sole();

    [$screening] = makeScreenings($theater, [CarbonImmutable::parse('2026-01-10 10:00:00')]);

    $this->travelTo(CarbonImmutable::parse($travelTo));

    expect(lockService()->acquire($screening, $seat, 'session:alice'))->toBeFalse();
})->with([
    '販売開始の1秒前（3日前 0:00 の直前）' => ['2026-01-06 23:59:59'],
    '上映開始時刻ちょうど' => ['2026-01-10 10:00:00'],
    '上映開始後' => ['2026-01-10 10:00:01'],
]);

it('販売開始時刻ちょうどはロックできる（4.3.1の境界）', function () {
    $theater = createTheater();
    $seatType = makeSeatsWithSurcharge($theater, 1, 0);
    $seat = Seat::where('seat_type_id', $seatType->id)->sole();

    [$screening] = makeScreenings($theater, [CarbonImmutable::parse('2026-01-10 10:00:00')]);

    $this->travelTo(CarbonImmutable::parse('2026-01-07 00:00:00'));

    expect(lockService()->acquire($screening, $seat, 'session:alice'))->toBeTrue();
});

it('1上映回で保持できるのは8席まで（4.3.4 / 17.8-2）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture(9);

    foreach (array_slice($seats, 0, SeatLockService::MAX_SEATS_PER_HOLDER) as $seat) {
        expect(lockService()->acquire($screening, $seat, 'session:alice'))->toBeTrue();
    }

    expect(lockService()->acquire($screening, $seats[8], 'session:alice'))->toBeFalse();
    expect(SeatLock::where('holder_key', 'session:alice')->count())->toBe(SeatLockService::MAX_SEATS_PER_HOLDER);
});

it('別の上映回のロックを保持したままでは取得できない（4.3.4）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeLockFixture();

    $other = makeOtherScreening($theater);

    expect(lockService()->acquire($screening, $seats[0], 'session:alice'))->toBeTrue();
    expect(lockService()->acquire($other, $seats[1], 'session:alice'))->toBeFalse();
});

it('releaseOtherScreenings は他の上映回のロックだけを解放する（4.3.4）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeLockFixture();

    $other = makeOtherScreening($theater);

    lockService()->acquire($screening, $seats[0], 'session:alice');
    lockService()->releaseOtherScreenings($other, 'session:alice');

    expect(SeatLock::where('holder_key', 'session:alice')->count())->toBe(0);
    expect(lockService()->acquire($other, $seats[1], 'session:alice'))->toBeTrue();
});

it('release は自分のロックのみを解放し、他者のロックは残す（17.8-4）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    lockService()->acquire($screening, $seats[0], 'session:alice');

    lockService()->release($screening, $seats[0], 'session:bob');
    expect(SeatLock::where('seat_id', $seats[0]->id)->count())->toBe(1);

    lockService()->release($screening, $seats[0], 'session:alice');
    expect(SeatLock::where('seat_id', $seats[0]->id)->count())->toBe(0);
});

it('extend は保持中のロックを延長し、期限切れのロックは延長しない（6.4.1-4）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    $now = CarbonImmutable::now()->setTime(10, 0);
    $this->travelTo($now);

    lockService()->acquire($screening, $seats[0], 'session:alice');

    $expired = SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seats[1]->id,
        'holder_key' => 'session:alice',
        'expires_at' => $now->subHour(),
    ]);

    lockService()->extend('session:alice', SeatLockService::PAYMENT_LOCK_MINUTES);

    expect(SeatLock::where('seat_id', $seats[0]->id)->sole()->expires_at->toDateTimeString())
        ->toBe($now->addMinutes(SeatLockService::PAYMENT_LOCK_MINUTES)->toDateTimeString());
    expect($expired->fresh()->expires_at->toDateTimeString())->toBe($now->subHour()->toDateTimeString());
});

it('releaseAll は保持者のロックのみをすべて解放する', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    lockService()->acquire($screening, $seats[0], 'session:alice');
    lockService()->acquire($screening, $seats[1], 'session:bob');

    lockService()->releaseAll('session:alice');

    expect(SeatLock::pluck('holder_key')->all())->toBe(['session:bob']);
});

it('transfer は移譲元に有効なロックが無ければ移譲先のロックに触れない（座席選択を伴わないログイン）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    // 会員が別タブで座席を選択中。新しいゲストセッション（ロック0件）からログインした状況。
    lockService()->acquire($screening, $seats[0], 'user:1');

    lockService()->transfer('session:guest', 'user:1');

    expect(SeatLock::where('holder_key', 'user:1')->count())->toBe(1);
});

it('transfer は移譲元の期限切れロックを移さずに削除する', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seats[0]->id,
        'holder_key' => 'session:guest',
        'expires_at' => CarbonImmutable::now()->subMinute(),
    ]);

    lockService()->transfer('session:guest', 'user:1');

    expect(SeatLock::count())->toBe(0);
});

it('transfer はロックの保持者を移し、移譲先の既存ロックを解放する（7.8 / 4.3.4）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeLockFixture();

    $other = makeOtherScreening($theater);

    lockService()->acquire($screening, $seats[0], 'session:guest');
    lockService()->acquire($other, $seats[1], 'user:1');

    lockService()->transfer('session:guest', 'user:1');

    $locks = SeatLock::get(['seat_id', 'holder_key', 'screening_id']);

    expect($locks)->toHaveCount(1);
    expect($locks->first()->holder_key)->toBe('user:1');
    expect($locks->first()->screening_id)->toBe($screening->id);
});

it('heldSeatIds は対象上映回で保持中の座席のみを返す', function () {
    ['screening' => $screening, 'seats' => $seats] = makeLockFixture();

    lockService()->acquire($screening, $seats[0], 'session:alice');
    lockService()->acquire($screening, $seats[1], 'session:bob');

    expect(lockService()->heldSeatIds($screening, 'session:alice'))->toBe([$seats[0]->id]);
});
