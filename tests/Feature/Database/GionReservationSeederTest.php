<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Cinema;
use App\Models\FreeTicket;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Stamp;
use App\Models\Theater;
use App\Models\User;
use Database\Seeders\GionReservationSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\SeedConfig;
use Illuminate\Support\Facades\Artisan;

/**
 * GionReservationSeeder は実在の祇園ムビデータではなく、slug=gion の
 * 最小フィクスチャに対して直接実行して検証する（ReservationSeederTest と同じ方針）。
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);
});

/**
 * GionReservationSeeder が参照する slug=gion のテスト用シアターを作成する。
 */
function createGionTheater(): Theater
{
    $cinema = Cinema::create([
        'slug' => SeedConfig::GION_SLUG,
        'name' => '祇園ムビ',
        'concept' => 'テスト用の祇園ムビ',
        'address' => '京都府京都市東山区祇園町南側',
        'phone' => '123-4567-8900',
        'business_hours' => '8:00〜23:55',
        'facility_info' => 'テスト',
        'access_note' => 'テスト',
        'map_embed_url' => 'https://example.com/map',
    ]);

    return Theater::create([
        'cinema_id' => $cinema->id,
        'number' => 1,
        'name' => '1番シアター',
    ]);
}

/**
 * GionReservationSeeder が要求する最小限の上映回（過去 GION_DEMO_STAMP_COUNT+1 件・
 * 販売期間内の未来3件）を、追加料金つきの座席・上映編成で用意する
 * （追加料金が0だと無料鑑賞券適用時の金額計算を検証できないため）。
 */
function seedGionFixtureScreenings(): Theater
{
    $theater = createGionTheater();
    makeSeatsWithSurcharge($theater, 10, 300);

    $pastStarts = collect(range(1, SeedConfig::STAMPS_PER_FREE_TICKET + 1))
        ->map(fn ($i) => now()->subDays($i * 3)->setTime(10, 0))
        ->all();
    $futureStarts = collect(range(1, 3))->map(fn ($i) => now()->addHours($i * 8))->all();
    makeScreenings($theater, [...$pastStarts, ...$futureStarts], bookingSurcharge: 200);

    return $theater;
}

function createGionTestMember(): User
{
    return User::factory()->create(['name' => 'Test User', 'email' => SeedConfig::TEST_MEMBER_EMAIL]);
}

test('running the seeder does nothing when the test member does not exist yet', function () {
    seedGionFixtureScreenings();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    expect(Reservation::query()->exists())->toBeFalse();
});

test('creates one reservation for every documented status', function () {
    seedGionFixtureScreenings();
    createGionTestMember();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    $statuses = Reservation::pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values()->all();
    $expected = collect(ReservationStatus::cases())->map(fn ($s) => $s->value)->sort()->values()->all();

    expect($statuses)->toBe($expected);
});

test('past visits grant a stamp regardless of check-in, and all five exchange for a free ticket', function () {
    // 4.5.1-2は「5個で無料鑑賞券1枚」と固定の値を定めており、SeedConfig側の定数が
    // 変わっても本テストが検出できるよう、仕様値そのものをリテラルで固定する
    expect(SeedConfig::STAMPS_PER_FREE_TICKET)->toBe(5);

    seedGionFixtureScreenings();
    $member = createGionTestMember();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    $stamps = Stamp::where('user_id', $member->id)->get();
    expect($stamps)->toHaveCount(5);
    expect($stamps->whereNotNull('free_ticket_id'))->toHaveCount(5);

    $freeTicket = FreeTicket::where('user_id', $member->id)->firstOrFail();
    expect($stamps->pluck('free_ticket_id')->unique()->all())->toBe([$freeTicket->id]);

    // 不来場（no-show）の来場にもスタンプが付いていること（4.5.1「入場有無を問わない」）
    $stampedReservationIds = $stamps->pluck('reservation_id');
    $noShowExists = Reservation::whereIn('id', $stampedReservationIds)->whereNull('checked_in_at')->exists();
    expect($noShowExists)->toBeTrue();
});

test('the free ticket usage reservation waives only the ticket price and grants no stamp', function () {
    seedGionFixtureScreenings();
    $member = createGionTestMember();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    $freeTicket = FreeTicket::where('user_id', $member->id)->firstOrFail();
    expect($freeTicket->used_at)->not->toBeNull();
    expect($freeTicket->reservation_id)->not->toBeNull();

    // 無料鑑賞券は、それを発行する根拠となったスタンプ（来場）より後にしか
    // 使用できない。発行前に使用された状態（時系列の逆転）になっていないこと
    expect($freeTicket->issued_at->lte($freeTicket->used_at))->toBeTrue();

    // 交換対象の5個のスタンプは、いずれも発行時刻以前（付与済み）であること
    $stamps = Stamp::where('free_ticket_id', $freeTicket->id)->get();
    expect($stamps)->toHaveCount(5);
    foreach ($stamps as $stamp) {
        expect($stamp->created_at->lte($freeTicket->issued_at))->toBeTrue();
    }

    $reservation = Reservation::findOrFail($freeTicket->reservation_id);
    $seat = ReservationSeat::where('reservation_id', $reservation->id)->firstOrFail();

    // 券種価格（2,000円）は含まれず、上映編成の追加料金(200円)＋座席種別の追加料金(300円)のみが残る
    expect($seat->amount)->toBe(500);
    expect($reservation->total_amount)->toBe(500);
    expect($reservation->stripe_payment_intent_id)->not->toBeNull();

    // 過去の上映回（決済済み・会員）だが、無料鑑賞券を使用した席にはスタンプを付与しない（4.5.1-4）
    expect($reservation->screening->starts_at->isPast())->toBeTrue();
    expect(Stamp::where('reservation_id', $reservation->id)->exists())->toBeFalse();
});

test('the cancelled reservation releases its seat', function () {
    seedGionFixtureScreenings();
    createGionTestMember();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    $reservation = Reservation::where('status', ReservationStatus::Cancelled)->firstOrFail();
    expect($reservation->cancelled_at)->not->toBeNull();
    expect($reservation->refunded_at)->not->toBeNull();

    $seat = ReservationSeat::where('reservation_id', $reservation->id)->firstOrFail();
    expect($seat->released_at)->not->toBeNull();
    expect($seat->active_seat_id)->toBeNull();
});

test('pending and expired reservations have no reservation seats', function () {
    seedGionFixtureScreenings();
    createGionTestMember();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    foreach ([ReservationStatus::Pending, ReservationStatus::Expired] as $status) {
        $reservation = Reservation::where('status', $status)->firstOrFail();
        expect($reservation->entry_code)->toBeNull();
        expect(ReservationSeat::where('reservation_id', $reservation->id)->exists())->toBeFalse();
    }
});

test('guest reservations have contact fields populated and no user_id, member reservations belong to the test member', function () {
    seedGionFixtureScreenings();
    $member = createGionTestMember();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    $guests = Reservation::where('contact_type', ContactType::Guest)->get();
    expect($guests)->not->toBeEmpty();
    foreach ($guests as $reservation) {
        expect($reservation->user_id)->toBeNull();
        expect($reservation->guest_name)->not->toBeNull();
        expect($reservation->guest_name_kana)->not->toBeNull();
        expect($reservation->guest_email)->not->toBeNull();
    }

    $members = Reservation::where('contact_type', ContactType::Member)->get();
    expect($members)->not->toBeEmpty();
    foreach ($members as $reservation) {
        expect($reservation->user_id)->toBe($member->id);
    }
});

test('no seat is assigned to more than one reservation within the same screening', function () {
    seedGionFixtureScreenings();
    createGionTestMember();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    $duplicates = ReservationSeat::selectRaw('screening_id, seat_id, count(*) as c')
        ->groupBy('screening_id', 'seat_id')
        ->havingRaw('count(*) > 1')
        ->get();

    expect($duplicates)->toBeEmpty();
});

test('re-seeding does not duplicate gion reservations', function () {
    seedGionFixtureScreenings();
    createGionTestMember();

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);
    $counts = [Reservation::count(), ReservationSeat::count(), Stamp::count(), FreeTicket::count()];

    Artisan::call('db:seed', ['--class' => GionReservationSeeder::class, '--force' => true]);

    expect([Reservation::count(), ReservationSeat::count(), Stamp::count(), FreeTicket::count()])->toBe($counts);
});
