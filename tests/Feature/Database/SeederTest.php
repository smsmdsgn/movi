<?php

use App\Models\Cinema;
use App\Models\Format;
use App\Models\Seat;
use App\Models\SeatType;
use App\Models\Theater;
use App\Models\TicketType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SeedConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('seeding creates the fixed master data', function () {
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect(Format::count())->toBe(count(SeedConfig::FORMATS));
    expect(SeatType::count())->toBe(count(SeedConfig::SEAT_TYPES));
    expect(TicketType::count())->toBe(count(SeedConfig::TICKET_TYPES));
});

test('seeding creates 7 cinemas with the fixed theater counts per cinema', function () {
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect(Cinema::count())->toBe(7);

    $gion = Cinema::where('slug', 'gion')->firstOrFail();
    expect($gion->theaters()->count())->toBe(3);

    foreach (SeedConfig::GENERATED_CINEMAS as $slug => $config) {
        $cinema = Cinema::where('slug', $slug)->firstOrFail();
        expect($cinema->theaters()->count())->toBe(count($config['theaters']));
    }
});

test('every theater has seats generated', function () {
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect(Theater::doesntHave('seats')->exists())->toBeFalse();
});

test('every generated theater supports 2D in addition to its assigned formats', function () {
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    $theaterWithoutTwoD = Theater::whereDoesntHave('formats', fn ($q) => $q->where('name', '2D'))->exists();

    expect($theaterWithoutTwoD)->toBeFalse();
});

test('gion theater 1 supports MOVI GRAND and MOVI VIVID in addition to 2D', function () {
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    $theater = Cinema::where('slug', 'gion')->firstOrFail()->theaters()->where('number', 1)->firstOrFail();

    expect($theater->formats()->pluck('name')->sort()->values()->all())
        ->toBe(['2D', 'MOVI GRAND', 'MOVI VIVID']);
});

test('MOVI MOTION is only assigned to shijo-kawaramachi, kyoto, and nijo', function () {
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

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
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    $executiveSeatTypeId = SeatType::where('name', SeedConfig::SEAT_TYPE_EXECUTIVE)->value('id');

    // fushimi の全シアターは M/S/XS 規模のみのため、エグゼクティブ席が存在しないはず
    $fushimiTheaterIds = Cinema::where('slug', 'fushimi')->firstOrFail()->theaters()->pluck('id');

    expect(Seat::whereIn('theater_id', $fushimiTheaterIds)->where('seat_type_id', $executiveSeatTypeId)->exists())
        ->toBeFalse();
});

test('the total generated seat count matches the fixed configuration', function () {
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect(Seat::count())->toBe(4880);
});

test('running the seeder twice does not duplicate any data', function () {
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
    $counts = [
        Cinema::count(),
        Theater::count(),
        Format::count(),
        SeatType::count(),
        TicketType::count(),
        Seat::count(),
        User::count(),
        DB::table('m_theater_format')->count(),
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
        DB::table('m_theater_format')->count(),
    ])->toBe($counts);
});
