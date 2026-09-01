<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Stamp;
use App\Models\TicketType;
use Carbon\CarbonImmutable;
use Database\Seeders\GionSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\MemberSeeder;
use Database\Seeders\ReservationSeeder;
use Database\Seeders\SeedConfig;
use Illuminate\Support\Facades\Artisan;

/**
 * ReservationSeeder は祇園ムビを除く6館・直近 `SeedConfig::RESERVATION_PAST_DAYS`
 * (docs/design.md 9.3追記表参照) に縮小したフル規模での実行を前提とするため、
 * ScreeningSeederTest と同様に最小限のフィクスチャに対して直接実行して検証する。
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);
});

test('no seat is reserved more than once for the same screening, and every reservation has at most 8 seats', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 40, 0);
    [$screening] = makeScreenings($theater, [now()->subDay()]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $seatIds = ReservationSeat::where('screening_id', $screening->id)->pluck('seat_id');
    expect($seatIds->count())->toBe($seatIds->unique()->count());

    $seatCountsPerReservation = ReservationSeat::where('screening_id', $screening->id)
        ->selectRaw('reservation_id, count(*) as seat_count')
        ->groupBy('reservation_id')
        ->pluck('seat_count');

    foreach ($seatCountsPerReservation as $count) {
        expect($count)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(8);
    }
});

test('occupied seat count for a screening stays within the 5-80% occupancy range', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 100, 0);
    [$screening] = makeScreenings($theater, [now()->subDay()]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $occupied = ReservationSeat::where('screening_id', $screening->id)->count();

    expect($occupied)->toBeGreaterThanOrEqual((int) round(100 * SeedConfig::RESERVATION_MIN_OCCUPANCY_PERCENT / 100));
    expect($occupied)->toBeLessThanOrEqual((int) round(100 * SeedConfig::RESERVATION_MAX_OCCUPANCY_PERCENT / 100));
});

test('each seat amount equals ticket price plus the booking surcharge plus the seat type surcharge', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 30, 300);
    [$screening] = makeScreenings($theater, [now()->subDay()], bookingSurcharge: 200);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $seats = ReservationSeat::where('screening_id', $screening->id)->with('ticketType')->get();

    expect($seats)->not->toBeEmpty();

    foreach ($seats as $seat) {
        expect($seat->amount)->toBe($seat->ticketType->price + 200 + 300);
    }

    $reservation = Reservation::where('screening_id', $screening->id)->first();
    $expectedTotal = (int) ReservationSeat::where('reservation_id', $reservation->id)->sum('amount');
    expect($reservation->total_amount)->toBe($expectedTotal);
});

test('a paid reservation always has an 8-digit reservation number and a 32-character entry code', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 20, 0);
    makeScreenings($theater, [now()->subDay()]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $reservations = Reservation::all();
    expect($reservations)->not->toBeEmpty();

    foreach ($reservations as $reservation) {
        expect($reservation->reservation_no)->toMatch('/^\d{8}$/');
        expect($reservation->entry_code)->toHaveLength(32);
        expect($reservation->status)->toBe(ReservationStatus::Paid);
        expect($reservation->stripe_payment_intent_id)->not->toBeNull();
    }

    expect($reservations->pluck('stripe_payment_intent_id')->unique()->count())->toBe($reservations->count());
});

test('only reservations for past screenings can be checked in, and not all of them are', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 40, 0);
    $pastStarts = collect(range(1, 13))->map(fn ($i) => now()->subDays($i)->setTime(12, 0))->all();
    $screenings = makeScreenings($theater, [...$pastStarts, now()->addDays(2)]);
    $futureScreening = end($screenings);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    // 未来の上映回（販売期間内）にも予約は生成されるが、入場済みにはならない
    expect(Reservation::where('screening_id', $futureScreening->id)->exists())->toBeTrue();
    expect(Reservation::where('screening_id', $futureScreening->id)->whereNotNull('checked_in_at')->exists())->toBeFalse();

    $pastReservations = Reservation::whereIn('screening_id', array_map(fn ($s) => $s->id, array_slice($screenings, 0, 13)))->get();
    expect($pastReservations->count())->toBeGreaterThan(10);

    $checkedInCount = $pastReservations->whereNotNull('checked_in_at')->count();
    expect($checkedInCount)->toBeGreaterThan(0);
    expect($checkedInCount)->toBeLessThan($pastReservations->count());
});

test('no reservations are generated for a screening beyond the sale window', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 20, 0);
    [$onSale, $notYetOnSale] = makeScreenings($theater, [
        now()->addDays(SeedConfig::RESERVATION_SALE_WINDOW_DAYS)->subHour(),
        now()->addDays(SeedConfig::RESERVATION_SALE_WINDOW_DAYS + 5),
    ]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    expect(Reservation::where('screening_id', $onSale->id)->exists())->toBeTrue();
    expect(Reservation::where('screening_id', $notYetOnSale->id)->exists())->toBeFalse();
});

test('a reservation is created within the sale window and always before its check-in time', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 60, 0);
    [$pastScreening, $futureScreening] = makeScreenings($theater, [now()->subDays(5), now()->addDay()]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $saleStart = $pastScreening->starts_at->startOfDay()->subDays(SeedConfig::RESERVATION_SALE_WINDOW_DAYS);
    $pastReservations = Reservation::where('screening_id', $pastScreening->id)->get();
    expect($pastReservations)->not->toBeEmpty();

    foreach ($pastReservations as $reservation) {
        expect($reservation->created_at->gte($saleStart))->toBeTrue();
        expect($reservation->created_at->lte($pastScreening->starts_at))->toBeTrue();

        if ($reservation->checked_in_at !== null) {
            expect($reservation->created_at->lt($reservation->checked_in_at))->toBeTrue();
        }
    }

    // 未来の上映回は、実行時刻を超えて「予約済み」にはならない
    $futureReservations = Reservation::where('screening_id', $futureScreening->id)->get();
    expect($futureReservations)->not->toBeEmpty();
    foreach ($futureReservations as $reservation) {
        expect($reservation->created_at->lte(now()))->toBeTrue();
    }
});

test('reservations are generated only within the recent window, not for screenings far in the past', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 20, 0);
    [$recent, $old] = makeScreenings($theater, [
        now()->subDays(SeedConfig::RESERVATION_PAST_DAYS - 1),
        now()->subDays(SeedConfig::RESERVATION_PAST_DAYS + 30),
    ]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    expect(Reservation::where('screening_id', $recent->id)->exists())->toBeTrue();
    expect(Reservation::where('screening_id', $old->id)->exists())->toBeFalse();
});

test('gion cinema is excluded from the bulk reservation seeder', function () {
    Artisan::call('db:seed', ['--class' => GionSeeder::class, '--force' => true]);
    $gionTheater = Cinema::where('slug', SeedConfig::GION_SLUG)->firstOrFail()->theaters()->firstOrFail();
    makeSeatsWithSurcharge($gionTheater, 20, 0);
    makeScreenings($gionTheater, [now()->subDay()]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    expect(Reservation::query()->exists())->toBeFalse();
});

test('both member and guest reservations are generated, with the corresponding fields populated exclusively', function () {
    Artisan::call('db:seed', ['--class' => MemberSeeder::class, '--force' => true]);

    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 60, 0);
    $starts = collect(range(1, 10))->map(fn ($i) => now()->subDays($i)->setTime(9, 0))->all();
    makeScreenings($theater, $starts);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $members = Reservation::where('contact_type', ContactType::Member)->get();
    $guests = Reservation::where('contact_type', ContactType::Guest)->get();

    expect($members)->not->toBeEmpty();
    expect($guests)->not->toBeEmpty();

    foreach ($members as $reservation) {
        expect($reservation->user_id)->not->toBeNull();
        expect($reservation->guest_name)->toBeNull();
        expect($reservation->guest_email)->toBeNull();
    }

    foreach ($guests as $reservation) {
        expect($reservation->user_id)->toBeNull();
        expect($reservation->guest_name)->not->toBeNull();
        expect($reservation->guest_name_kana)->not->toBeNull();
        expect($reservation->guest_email)->not->toBeNull();
        expect($reservation->guest_phone)->not->toBeNull();
    }
});

test('a stamp is granted only for member reservations tied to a past screening', function () {
    Artisan::call('db:seed', ['--class' => MemberSeeder::class, '--force' => true]);

    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 60, 0);
    $starts = collect(range(1, 10))->map(fn ($i) => now()->subDays($i)->setTime(9, 0))->all();
    $screenings = makeScreenings($theater, [...$starts, now()->addDays(2)]);
    $futureScreening = end($screenings);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    expect(Stamp::count())->toBeGreaterThan(0);
    expect(Stamp::whereHas('reservation', fn ($q) => $q->where('screening_id', $futureScreening->id))->exists())->toBeFalse();
    expect(Stamp::whereHas('reservation', fn ($q) => $q->where('contact_type', ContactType::Guest))->exists())->toBeFalse();
});

test('a member never accumulates more stamps than the configured cap', function () {
    Artisan::call('db:seed', ['--class' => MemberSeeder::class, '--force' => true]);

    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 200, 0);
    $starts = collect(range(1, 13))->map(fn ($i) => now()->subDays($i)->setTime(9, 0))->all();
    makeScreenings($theater, $starts);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $maxStampsPerUser = Stamp::selectRaw('user_id, count(*) as stamp_count')
        ->groupBy('user_id')
        ->get()
        ->max('stamp_count');

    expect($maxStampsPerUser)->not->toBeNull();
    expect($maxStampsPerUser)->toBeLessThanOrEqual(SeedConfig::RESERVATION_STAMP_CAP);
    // このフィクスチャの生成量であれば、上限に達する会員が実際に現れる
    expect($maxStampsPerUser)->toBe(SeedConfig::RESERVATION_STAMP_CAP);
});

test('re-seeding does not duplicate reservations, reservation seats, or stamps', function () {
    Artisan::call('db:seed', ['--class' => MemberSeeder::class, '--force' => true]);

    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 30, 0);
    makeScreenings($theater, [now()->subDay()]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);
    $counts = [Reservation::count(), ReservationSeat::count(), Stamp::count()];

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    expect([Reservation::count(), ReservationSeat::count(), Stamp::count()])->toBe($counts);
});

test('re-seeding does not skip theaters just because an unrelated screening already has a reservation', function () {
    Artisan::call('db:seed', ['--class' => MemberSeeder::class, '--force' => true]);

    $theaterA = createTheater();
    makeSeatsWithSurcharge($theaterA, 20, 0);
    [$screeningA] = makeScreenings($theaterA, [now()->subDay()]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);
    expect(Reservation::where('screening_id', $screeningA->id)->exists())->toBeTrue();

    // 既に t_reservations が存在する状態（上のシーダー実行結果）でも、
    // 別のシアター・上映回にはきちんと予約が生成されること（冪等性は上映回単位のはず）
    $theaterB = createTheater();
    makeSeatsWithSurcharge($theaterB, 20, 0);
    [$screeningB] = makeScreenings($theaterB, [now()->subDay()]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    expect(Reservation::where('screening_id', $screeningB->id)->exists())->toBeTrue();
});

test('running the seeder raises an error when a ticket type has no configured weight', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 10, 0);
    makeScreenings($theater, [now()->subDay()]);
    TicketType::create(['name' => '未定義券種', 'price' => 500, 'display_order' => 99]);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);
})->throws(RuntimeException::class, 'TICKET_TYPE_WEIGHTS');

test('re-seeding after time has advanced carries over reservation numbering, guest contacts, and the stamp cap from the database', function () {
    Artisan::call('db:seed', ['--class' => MemberSeeder::class, '--force' => true]);

    // 上限に到達する会員が確実に現れる規模（'a member never accumulates more stamps
    // than the configured cap' で実証済みの規模と同じ）にする
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 200, 0);
    $starts = collect(range(1, 13))->map(fn ($i) => now()->subDays($i)->setTime(9, 0))->all();
    makeScreenings($theater, $starts);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);
    $firstRunCount = Reservation::count();

    // このシーダーは常に guestSequence 番目のメールアドレス（guest{n}@example.com）を
    // 発行するため、引き継ぎが壊れて0から採番し直すと決定的に guest1@example.com から
    // 重複する。DBの既存カウントから引き継いでいることの直接的な証拠になる
    $cappedUserIds = Stamp::selectRaw('user_id, count(*) as stamp_count')
        ->groupBy('user_id')
        ->havingRaw('count(*) = ?', [SeedConfig::RESERVATION_STAMP_CAP])
        ->pluck('user_id');
    expect($cappedUserIds)->not->toBeEmpty();

    // 販売期間（実行時刻から3日先まで）は実時刻とともに移動するため、翌日以降の
    // 再実行では新たに対象となる上映回が生じうる。この経路で採番・非会員連絡先・
    // スタンプ上限のいずれも、DBの既存行と衝突・超過しないことを検証する
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());

    try {
        [$newScreening] = makeScreenings($theater, [CarbonImmutable::now()->subHour()]);

        Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);
    } finally {
        CarbonImmutable::setTestNow();
    }

    expect(Reservation::where('screening_id', $newScreening->id)->exists())->toBeTrue();
    expect(Reservation::count())->toBeGreaterThan($firstRunCount);

    $allNos = Reservation::pluck('reservation_no');
    expect($allNos->count())->toBe($allNos->unique()->count());

    $guestEmails = Reservation::whereNotNull('guest_email')->pluck('guest_email');
    expect($guestEmails->count())->toBe($guestEmails->unique()->count());

    // 1回目実行で上限に達していた会員が、2回目実行後も上限を超えていないこと
    $stampCountsAfter = Stamp::selectRaw('user_id, count(*) as stamp_count')
        ->whereIn('user_id', $cappedUserIds)
        ->groupBy('user_id')
        ->pluck('stamp_count', 'user_id');

    foreach ($cappedUserIds as $userId) {
        expect((int) $stampCountsAfter[$userId])->toBe(SeedConfig::RESERVATION_STAMP_CAP);
    }
});
