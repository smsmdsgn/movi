<?php

use App\Enums\AdminRole;
use App\Http\Middleware\SkipCinemaScope;
use App\Livewire\Front\Schedule\ScheduleTable;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Theater;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

/**
 * 予約座席を1件作成し、`released_at` を設定した状態（解放済み）で返す。
 */
function releasedReservationSeat(int $screeningId, int $seatId, int $ticketTypeId): ReservationSeat
{
    $reservationSeat = createReservationSeat($screeningId, $seatId, $ticketTypeId);
    $reservationSeat->released_at = now();
    $reservationSeat->save();

    return $reservationSeat;
}

/**
 * 座席ロックを1件作成する。
 */
function makeSeatLock(int $screeningId, int $seatId, CarbonImmutable $expiresAt): SeatLock
{
    return SeatLock::create([
        'screening_id' => $screeningId,
        'seat_id' => $seatId,
        'holder_key' => 'session:'.Str::random(12),
        'expires_at' => $expiresAt,
    ]);
}

/**
 * レンダリング済みHTMLから、指定した上映回IDの `data-availability` の値を取り出す。
 */
function availabilityOf(string $html, int $screeningId): ?string
{
    if (preg_match('/wire:key="slot-'.$screeningId.'"[^>]*>.*?data-availability="([^"]+)"/s', $html, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

it('当日の上映回が作品ブロックにまとまり開始時刻・シアター名が表示される。開始済みの回は表示されない（4.2.2-6）', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 12:00:00'));

    $theater = createTheater();
    $cinema = $theater->cinema;

    makeScreenings($theater, [
        CarbonImmutable::parse('2026-01-10 10:00:00'),
        CarbonImmutable::parse('2026-01-10 14:00:00'),
    ]);

    Livewire::test(ScheduleTable::class, ['cinema' => $cinema])
        ->assertDontSee('10:00')
        ->assertSee('14:00')
        ->assertSee($theater->name);
});

it('別の日の上映回は当日タブに出ず、selectDateで表示される', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $theater = createTheater();
    $cinema = $theater->cinema;
    $tomorrow = CarbonImmutable::parse('2026-01-11 14:00:00');

    makeScreenings($theater, [$tomorrow]);

    $component = Livewire::test(ScheduleTable::class, ['cinema' => $cinema])
        ->assertDontSee('14:00');

    $component->call('selectDate', $tomorrow->toDateString())
        ->assertSee('14:00');
});

it('selectDateに表示範囲外・不正な日付を渡すと拒否され、dateが変わらない', function (string $invalidDate) {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $cinema = createCinema();

    Livewire::test(ScheduleTable::class, ['cinema' => $cinema])
        ->call('selectDate', $invalidDate)
        ->assertHasErrors('date')
        ->assertSet('date', '2026-01-10');
})->with([
    '8日後（表示範囲外）' => ['2026-01-18'],
    '過去日' => ['2026-01-09'],
    '不正な文字列' => ['not-a-date'],
]);

it('空席数を座席総数・予約座席・座席ロックから正しく算出する（7.4）', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $theater = createTheater();
    $cinema = $theater->cinema;

    makeSeatsWithSurcharge($theater, 10, 0);
    /** @var Collection<int, Seat> $seats */
    $seats = Seat::where('theater_id', $theater->id)->orderBy('id')->get();

    // is_available = false の座席は総数から除く（総数10のまま変わらないことを他シナリオで検証する）。
    Seat::create([
        'theater_id' => $theater->id,
        'seat_type_id' => $seats->first()->seat_type_id,
        'row_label' => 'ZY',
        'seat_number' => '001',
        'grid_row' => 998,
        'grid_col' => 1,
        'is_available' => false,
    ]);

    $ticketTypeId = createTicketType()->id;

    $times = [
        'available' => CarbonImmutable::parse('2026-01-10 10:00:00'),
        'few' => CarbonImmutable::parse('2026-01-10 11:00:00'),
        'full' => CarbonImmutable::parse('2026-01-10 12:00:00'),
        'released_not_counted' => CarbonImmutable::parse('2026-01-10 13:00:00'),
        'expired_lock_not_counted' => CarbonImmutable::parse('2026-01-10 14:00:00'),
        'valid_lock_counted' => CarbonImmutable::parse('2026-01-10 15:00:00'),
        'duplicate_not_double_counted' => CarbonImmutable::parse('2026-01-10 16:00:00'),
    ];

    $screenings = makeScreenings($theater, array_values($times));
    $screeningIds = array_combine(array_keys($times), array_map(fn ($s) => $s->id, $screenings));

    // available: 予約座席0
    // (何もしない)

    // few: 予約座席7（残3 = 30%ちょうど）
    foreach ($seats->take(7) as $seat) {
        createReservationSeat($screeningIds['few'], $seat->id, $ticketTypeId);
    }

    // full: 予約座席10
    foreach ($seats as $seat) {
        createReservationSeat($screeningIds['full'], $seat->id, $ticketTypeId);
    }

    // released_not_counted: 全席解放済みの予約座席（数えない）
    foreach ($seats as $seat) {
        releasedReservationSeat($screeningIds['released_not_counted'], $seat->id, $ticketTypeId);
    }

    // expired_lock_not_counted: 有効期限切れの座席ロック（数えない）
    foreach ($seats as $seat) {
        makeSeatLock($screeningIds['expired_lock_not_counted'], $seat->id, now()->subMinute());
    }

    // valid_lock_counted: 有効な座席ロック8席（残2 = 20%）
    foreach ($seats->take(8) as $seat) {
        makeSeatLock($screeningIds['valid_lock_counted'], $seat->id, now()->addMinutes(10));
    }

    // duplicate_not_double_counted: 同一座席に予約とロックが重複（7席、残3。二重に数えると満席扱いになってしまう）
    foreach ($seats->take(7) as $seat) {
        createReservationSeat($screeningIds['duplicate_not_double_counted'], $seat->id, $ticketTypeId);
        makeSeatLock($screeningIds['duplicate_not_double_counted'], $seat->id, now()->addMinutes(10));
    }

    $html = Livewire::test(ScheduleTable::class, ['cinema' => $cinema])->html();

    expect(availabilityOf($html, $screeningIds['available']))->toBe('available');
    expect(availabilityOf($html, $screeningIds['few']))->toBe('few');
    expect(availabilityOf($html, $screeningIds['full']))->toBe('full');
    expect(availabilityOf($html, $screeningIds['released_not_counted']))->toBe('available');
    expect(availabilityOf($html, $screeningIds['expired_lock_not_counted']))->toBe('available');
    expect(availabilityOf($html, $screeningIds['valid_lock_counted']))->toBe('few');
    expect(availabilityOf($html, $screeningIds['duplicate_not_double_counted']))->toBe('few');
});

it('販売開始前の上映回は販売前として非活性表示になる（4.2.2-5）', function () {
    $today = CarbonImmutable::parse('2026-01-10 00:00:00');
    $this->travelTo($today);

    $theater = createTheater();
    $cinema = $theater->cinema;
    makeSeatsWithSurcharge($theater, 10, 0);

    $onSaleDay = $today->addDays(3)->setTime(10, 0);
    $beforeSaleDay = $today->addDays(4)->setTime(10, 0);

    [$onSaleScreening, $beforeSaleScreening] = makeScreenings($theater, [$onSaleDay, $beforeSaleDay]);

    $onSaleHtml = Livewire::test(ScheduleTable::class, ['cinema' => $cinema])
        ->call('selectDate', $onSaleDay->toDateString())
        ->html();
    expect(availabilityOf($onSaleHtml, $onSaleScreening->id))->toBe('available');
    expect($onSaleHtml)->toContain('href="'.route('front.reservation.seats', ['id' => $onSaleScreening->id]).'"');

    $beforeSaleHtml = Livewire::test(ScheduleTable::class, ['cinema' => $cinema])
        ->call('selectDate', $beforeSaleDay->toDateString())
        ->html();
    expect(availabilityOf($beforeSaleHtml, $beforeSaleScreening->id))->toBe('before_sale');
    expect($beforeSaleHtml)->not->toContain(route('front.reservation.seats', ['id' => $beforeSaleScreening->id]));
});

it('他館の上映回は表示されない（t_bookings.cinema_id で判定）', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $theaterA = createTheater();
    $cinemaA = $theaterA->cinema;
    $theaterB = createTheater();

    makeScreenings($theaterB, [CarbonImmutable::parse('2026-01-10 14:00:00')]);

    Livewire::test(ScheduleTable::class, ['cinema' => $cinemaA])
        ->assertDontSee('14:00');
});

it('cinema-admin で他館の管理セッションを保持したまま P-22 を開いても、その館の上映回が表示される（SkipCinemaScope）', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $kyotoTheater = Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    makeScreenings($kyotoTheater, [CarbonImmutable::parse('2026-01-10 14:00:00')]);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin')
        ->get(route('front.schedule.index', ['slug' => 'kyoto']))
        ->assertOk()
        ->assertSee('14:00');
});

it('titleが「上映スケジュール｜{館名}｜MOVI」で、上映回ボタンが座席選択画面へリンクする', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $cinema = createCinema('gion', '祇園ムビ');
    $theater = Theater::create(['cinema_id' => $cinema->id, 'number' => 1, 'name' => '1番シアター']);

    [$screening] = makeScreenings($theater, [CarbonImmutable::parse('2026-01-10 14:00:00')]);

    $this->get(route('front.schedule.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('<title>上映スケジュール｜祇園ムビ｜MOVI</title>', false)
        ->assertSee('href="'.route('front.reservation.seats', ['id' => $screening->id]).'"', false);
});

it('座席が1席も投入されていないシアターの上映回は満席として扱う（4.2.3追記表）', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $theater = createTheater();
    [$screening] = makeScreenings($theater, [CarbonImmutable::parse('2026-01-10 14:00:00')]);

    $html = Livewire::test(ScheduleTable::class, ['cinema' => $theater->cinema])->html();

    expect(availabilityOf($html, $screening->id))->toBe('full');
});

it('selectDate を経由せず date を直接書き換えても、表示範囲外の値は当日へ戻される（17.5.1-2）', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $theater = createTheater();
    makeScreenings($theater, [CarbonImmutable::parse('2026-01-10 14:00:00')]);

    Livewire::test(ScheduleTable::class, ['cinema' => $theater->cinema])
        ->set('date', '2020-01-01')
        ->assertSet('date', '2026-01-10')
        ->assertSee('14:00');
});

it('SkipCinemaScope が Livewire の永続ミドルウェアに登録されている（/livewire/update でも館スコープを適用しない）', function () {
    /*
     * `Livewire::test()` は永続ミドルウェアを通さないため、日付切替（/livewire/update）の
     * 経路は登録の有無で固定する。登録が外れると、管理者のセッションが残ったブラウザで
     * 日付を切り替えた時点で他館の上映回が消える（4.2.3追記表）。
     */
    $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

    expect($persistent)->toContain(SkipCinemaScope::class);
});
