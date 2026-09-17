<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Stamp;
use App\Models\TicketType;
use App\Models\User;
use App\Services\PricingService;
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

/**
 * 会員1名のスタンプを `SeedConfig::RESERVATION_STAMP_CAP` 件まで積み、そのIDを返す。
 *
 * 予約を持つ上映回は `ReservationSeeder` の冪等判定
 * （`whereDoesntHave('reservations')`）から外れるため、ここで作る上映回と予約は
 * シーダーに上書きされない。
 */
function capUserStamps(): int
{
    $user = User::factory()->create();
    $screening = createScreeningForTheater(createTheater());

    foreach (range(1, SeedConfig::RESERVATION_STAMP_CAP) as $ignored) {
        $reservation = Reservation::create([
            'reservation_no' => nextTestReservationNo(),
            'user_id' => $user->id,
            'contact_type' => ContactType::Member,
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Paid,
            'total_amount' => 2000,
        ]);

        Stamp::create(['user_id' => $user->id, 'reservation_id' => $reservation->id]);
    }

    return $user->id;
}

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

/*
 * 金額は工程5-h から PricingService::calculateResolved() で求める（旧12章 残課題7）。
 * `now()->subDay()` は実行時刻の時分をそのまま引き継ぐため、レイトショー（6.5.2、
 * 開始時刻20:00以降）の成否がテストの実行時刻に左右される。金額の検証では
 * 時刻を明示的に固定し、レイトショーが成立する・しない双方を確定的に再現する。
 */

test('each seat amount matches PricingService for the seeded seat/ticket assignment (regular hours)', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 30, 300);
    // 10:00 開始（レイトショーの対象外）に固定する。
    [$screening] = makeScreenings($theater, [now()->subDay()->setTime(10, 0)], bookingSurcharge: 200);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    assertSeededAmountsMatchPricingService($screening->id);
});

test('a late-show screening discounts every occupied seat（6.5.2）', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 30, 300);
    // 21:00 開始（レイトショーの対象）に固定する。
    [$screening] = makeScreenings($theater, [now()->subDay()->setTime(21, 0)], bookingSurcharge: 200);

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $seats = ReservationSeat::where('screening_id', $screening->id)->with('ticketType')->get();
    expect($seats)->not->toBeEmpty();

    // 全席がレイトショーで割引されることを固定する。ペア割が勝つ場合は対象外の席
    // （余りの1枚・非大人席）が割引0円のままになりうるため、この assertion は
    // 「同額時はレイトショーを優先する」（6.5.2-1）に依存する。MasterDataSeeder の
    // 大人券種価格（2,000円）ではペア割の1席あたり割引（500円）がレイトショーと
    // 常に同額になり、レイトショーが勝つ。大人価格を変えると崩れる前提であることに注意。
    foreach ($seats as $seat) {
        $regular = $seat->ticketType->price + 200 + 300;
        expect($seat->amount)->toBeLessThan($regular);
    }

    assertSeededAmountsMatchPricingService($screening->id);
});

/**
 * 保存済みの座席・券種の組み合わせで PricingService::calculate() を呼び直し、
 * 保存された amount / total_amount と一致することを確認する。
 *
 * 割引の判定・下限そのものは tests/Feature/Reservation/PricingServiceTest.php が
 * 担保するため、ここでは「シーダーが計算した金額」と「PricingService が計算する
 * 金額」が食い違わないこと（旧12章 残課題7 の再発防止）だけを確認する。
 */
function assertSeededAmountsMatchPricingService(int $screeningId): void
{
    $reservations = Reservation::where('screening_id', $screeningId)->with('seats')->get();
    expect($reservations)->not->toBeEmpty();

    $pricing = app(PricingService::class);

    foreach ($reservations as $reservation) {
        $seatSelections = $reservation->seats->pluck('ticket_type_id', 'seat_id')->all();

        // `calculate()` は取り直した Screening を渡しても preventLazyLoading を踏まない
        // （単一行の find() 由来）が、キャッシュを避け実際の呼び出し経路に揃えるため
        // 毎回取り直す。
        $breakdown = $pricing->calculate(Screening::findOrFail($screeningId), $seatSelections);
        $expectedBySeat = collect($breakdown->seats)->keyBy('seatId');

        foreach ($reservation->seats as $seat) {
            expect($seat->amount)->toBe($expectedBySeat[$seat->seat_id]->amount());
        }

        expect($reservation->total_amount)->toBe($breakdown->total());
    }
}

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

    // 上限に到達済みの会員を明示的に用意する。シーダーが上限到達者を「生む」ことに
    // 依存すると、`capUserStamps()` の説明のとおり AUTO_INCREMENT のずれで結果が変わる。
    $cappedUserId = capUserStamps();

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);

    $stampCountsPerUser = Stamp::selectRaw('user_id, count(*) as stamp_count')
        ->groupBy('user_id')
        ->pluck('stamp_count', 'user_id');

    expect($stampCountsPerUser)->not->toBeEmpty();
    // 誰も上限を超えない（シーダー内のカウントとDBからの引き継ぎの双方が効いている）
    expect($stampCountsPerUser->max())->toBeLessThanOrEqual(SeedConfig::RESERVATION_STAMP_CAP);
    // 既に上限へ達している会員は、実行後も上限のまま据え置かれる
    expect((int) $stampCountsPerUser[$cappedUserId])->toBe(SeedConfig::RESERVATION_STAMP_CAP);
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

    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 200, 0);
    $starts = collect(range(1, 13))->map(fn ($i) => now()->subDays($i)->setTime(9, 0))->all();
    makeScreenings($theater, $starts);

    // スタンプ上限に到達済みの会員をDB上に明示的に用意する。
    //
    // **シーダーが上限到達者を生むことに依存してはならない。** このシーダーの
    // 擬似乱数は `crc32("{$screening->id}-{$groupIndex}")` を種としており
    // （DeterministicRandom）、MariaDB の AUTO_INCREMENT は RefreshDatabase の
    // ロールバックで戻らないため、先行するテストが上映回を作った数だけ id が進み
    // 会員の割り当てが変わる。上限到達者の有無が実行順に左右され、
    // このテストの前提が壊れる。
    $cappedUserId = capUserStamps();

    Artisan::call('db:seed', ['--class' => ReservationSeeder::class, '--force' => true]);
    $firstRunCount = Reservation::count();

    // このシーダーは常に guestSequence 番目のメールアドレス（guest{n}@example.com）を
    // 発行するため、引き継ぎが壊れて0から採番し直すと決定的に guest1@example.com から
    // 重複する。DBの既存カウントから引き継いでいることの直接的な証拠になる
    $cappedUserIds = Stamp::selectRaw('user_id, count(*) as stamp_count')
        ->groupBy('user_id')
        ->havingRaw('count(*) = ?', [SeedConfig::RESERVATION_STAMP_CAP])
        ->pluck('user_id');
    expect($cappedUserIds)->toContain($cappedUserId);

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
