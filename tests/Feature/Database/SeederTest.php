<?php

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\PostCategory;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatType;
use App\Models\Theater;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GeneratedCinemaSeeder;
use Database\Seeders\GionSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\MovieSeeder;
use Database\Seeders\SeedConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 館・シアター・座席のみを対象とするテスト用に、上映作品・上映編成・上映回を
 * 投入しない範囲でシーダーを実行する（ScreeningSeeder は45シアター全体で約36秒かかり、
 * 本テストの関心事ではないため）。
 */
function seedCinemasAndSeatsOnly(): void
{
    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => GionSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => GeneratedCinemaSeeder::class, '--force' => true]);
}

test('seeding creates the fixed master data', function () {
    seedCinemasAndSeatsOnly();

    expect(Format::count())->toBe(count(SeedConfig::FORMATS));
    expect(SeatType::count())->toBe(count(SeedConfig::SEAT_TYPES));
    expect(TicketType::count())->toBe(count(SeedConfig::TICKET_TYPES));
});

test('seeding creates the post categories and a single super-admin account', function () {
    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);

    expect(PostCategory::count())->toBe(count(SeedConfig::POST_CATEGORIES));
    expect(Admin::count())->toBe(1);

    $admin = Admin::where('login_id', SeedConfig::SUPER_ADMIN_LOGIN_ID)->firstOrFail();
    expect($admin->role)->toBe(AdminRole::SuperAdmin);
    expect($admin->cinema_id)->toBeNull();
});

test('the super-admin password comes from the configured value when present', function () {
    Config::set('services.seed.super_admin_password', 'a-known-dev-password');

    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);

    $admin = Admin::where('login_id', SeedConfig::SUPER_ADMIN_LOGIN_ID)->firstOrFail();
    expect(Hash::check('a-known-dev-password', $admin->password))->toBeTrue();
});

test('the super-admin password is randomly generated when the configured value is blank', function () {
    Config::set('services.seed.super_admin_password', '');

    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);

    $admin = Admin::where('login_id', SeedConfig::SUPER_ADMIN_LOGIN_ID)->firstOrFail();
    expect(Hash::check('', $admin->password))->toBeFalse();
    expect(Artisan::output())->toContain('ランダム生成しました');
});

test('seeding creates 7 cinemas with the fixed theater counts per cinema', function () {
    seedCinemasAndSeatsOnly();

    expect(Cinema::count())->toBe(7);

    $gion = Cinema::where('slug', 'gion')->firstOrFail();
    expect($gion->theaters()->count())->toBe(3);

    foreach (SeedConfig::GENERATED_CINEMAS as $slug => $config) {
        $cinema = Cinema::where('slug', $slug)->firstOrFail();
        expect($cinema->theaters()->count())->toBe(count($config['theaters']));
    }
});

test('every theater has seats generated', function () {
    seedCinemasAndSeatsOnly();

    expect(Theater::doesntHave('seats')->exists())->toBeFalse();
});

test('every generated theater supports 2D in addition to its assigned formats', function () {
    seedCinemasAndSeatsOnly();

    $theaterWithoutTwoD = Theater::whereDoesntHave('formats', fn ($q) => $q->where('name', '2D'))->exists();

    expect($theaterWithoutTwoD)->toBeFalse();
});

test('gion theater 1 supports MOVI GRAND and MOVI VIVID in addition to 2D', function () {
    seedCinemasAndSeatsOnly();

    $theater = Cinema::where('slug', 'gion')->firstOrFail()->theaters()->where('number', 1)->firstOrFail();

    expect($theater->formats()->pluck('name')->sort()->values()->all())
        ->toBe(['2D', 'MOVI GRAND', 'MOVI VIVID']);
});

test('MOVI MOTION is only assigned to shijo-kawaramachi, kyoto, and nijo', function () {
    seedCinemasAndSeatsOnly();

    $slugs = Theater::whereHas('formats', fn ($q) => $q->where('name', 'MOVI MOTION'))
        ->with('cinema')
        ->get()
        ->pluck('cinema.slug')
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($slugs)->toBe(['kyoto', 'nijo', 'shijo-kawaramachi']);
});

test('fushimi has no executive seats because none of its theaters are L scale or larger', function () {
    seedCinemasAndSeatsOnly();

    $executiveSeatTypeId = SeatType::where('name', SeedConfig::SEAT_TYPE_EXECUTIVE)->value('id');

    // fushimi の全シアターは M/S/XS 規模のみのため、エグゼクティブ席が存在しないはず
    $fushimiTheaterIds = Cinema::where('slug', 'fushimi')->firstOrFail()->theaters()->pluck('id');

    expect(Seat::whereIn('theater_id', $fushimiTheaterIds)->where('seat_type_id', $executiveSeatTypeId)->exists())
        ->toBeFalse();
});

test('the total generated seat count matches the fixed configuration', function () {
    seedCinemasAndSeatsOnly();

    expect(Seat::count())->toBe(4880);
});

test('seeding creates the fixed movie pool with format associations', function () {
    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => MovieSeeder::class, '--force' => true]);

    expect(Movie::count())->toBe(count(SeedConfig::MOVIES));

    // 2Dのみの作品（追加規格なし）
    $oldFilm = Movie::where('title', '潮騒の記憶')->firstOrFail();
    expect($oldFilm->formats()->pluck('name')->all())->toBe(['2D']);

    // 2D + GRAND + 3D + MOTION の大作
    $blockbuster = Movie::where('title', 'アステロイド・ゼロ')->firstOrFail();
    expect($blockbuster->formats()->pluck('name')->sort()->values()->all())
        ->toBe(['2D', 'MOVI 3D', 'MOVI GRAND', 'MOVI MOTION']);

    // SeedConfig::MOVIES の formats（2D以外の追加規格）が1件も取りこぼされていないことを
    // m_movie_format の総行数から確認する（whereIn に未定義の規格名を書いても例外にならず
    // 静かに取りこぼすため）
    $expectedPivotRows = collect(SeedConfig::MOVIES)->sum(fn ($movie) => 1 + count($movie['formats']));
    expect(DB::table('m_movie_format')->count())->toBe($expectedPivotRows);
});

test('running the full seeder twice does not duplicate any data', function () {
    // ReservationSeeder は4.3.1の販売期間（実行時刻から3日先まで）を基準に対象の
    // 上映回を絞り込むため、2回の実行の間に実時間が経過すると、新たに販売期間へ
    // 入った上映回の分だけ予約が増分してしまい、時刻を固定しないと本テストの
    // 「完全に同一件数」という前提が崩れる。時刻を固定して実時間の経過を無効化する
    CarbonImmutable::setTestNow(CarbonImmutable::now());

    try {
        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        // test@example.com が MemberSeeder より前に作成され、会員プールに含まれることの確認
        // （9.3追記表。順序を誤ると test@example.com に予約・スタンプが一切紐づかなくなる）
        expect(
            Reservation::whereHas('user', fn ($q) => $q->where('email', 'test@example.com'))->exists()
        )->toBeTrue();

        // 祇園ムビの予約データ（動作確認用に手動投入。9.1 / GionReservationSeeder）が
        // 実際に投入されていることの確認
        expect(
            Reservation::whereHas('screening.theater.cinema', fn ($q) => $q->where('slug', SeedConfig::GION_SLUG))->exists()
        )->toBeTrue();

        $counts = [
            Cinema::count(),
            Theater::count(),
            Format::count(),
            SeatType::count(),
            TicketType::count(),
            Seat::count(),
            User::count(),
            Movie::count(),
            Admin::count(),
            PostCategory::count(),
            DB::table('m_theater_format')->count(),
            DB::table('m_movie_format')->count(),
            DB::table('t_bookings')->count(),
            DB::table('t_screenings')->count(),
            DB::table('t_reservations')->count(),
            DB::table('t_reservation_seats')->count(),
            DB::table('t_stamps')->count(),
            DB::table('t_free_tickets')->count(),
            DB::table('c_posts')->count(),
            DB::table('c_banners')->count(),
        ];

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        expect([
            Cinema::count(),
            Theater::count(),
            Format::count(),
            SeatType::count(),
            TicketType::count(),
            Seat::count(),
            User::count(),
            Movie::count(),
            Admin::count(),
            PostCategory::count(),
            DB::table('m_theater_format')->count(),
            DB::table('m_movie_format')->count(),
            DB::table('t_bookings')->count(),
            DB::table('t_screenings')->count(),
            DB::table('t_reservations')->count(),
            DB::table('t_reservation_seats')->count(),
            DB::table('t_stamps')->count(),
            DB::table('t_free_tickets')->count(),
            DB::table('c_posts')->count(),
            DB::table('c_banners')->count(),
        ])->toBe($counts);
    } finally {
        CarbonImmutable::setTestNow();
    }
})->group('slow');
