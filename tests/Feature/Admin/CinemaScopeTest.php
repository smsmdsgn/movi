<?php

use App\Enums\AdminRole;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Theater;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('super-admin は全館のシアターを取得できる（T-02）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $admin = createAdmin(AdminRole::SuperAdmin);
    $this->actingAs($admin, 'admin');

    expect(Theater::count())->toBe(2);
});

it('cinema-admin は自館のシアターのみ取得できる（T-02）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $ownTheater = Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $admin = createAdmin(AdminRole::CinemaAdmin, $gion);
    $this->actingAs($admin, 'admin');

    $theaters = Theater::all();

    expect($theaters)->toHaveCount(1);
    expect($theaters->first()->id)->toBe($ownTheater->id);
});

it('cinema-admin はIDを指定しても他館のシアターを取得できない（T-02 / 17.2.1）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    $otherTheater = Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $admin = createAdmin(AdminRole::CinemaAdmin, $gion);
    $this->actingAs($admin, 'admin');

    expect(Theater::find($otherTheater->id))->toBeNull();
});

it('cinema-admin は他館のシアターを一括更新できない（T-02）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    $otherTheater = Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $admin = createAdmin(AdminRole::CinemaAdmin, $gion);
    $this->actingAs($admin, 'admin');

    $updated = Theater::where('id', $otherTheater->id)->update(['name' => '書き換え']);

    expect($updated)->toBe(0);
    expect($otherTheater->fresh()->name)->toBe('1番シアター');
});

it('所属館が未設定の cinema-admin がシアターを取得しようとすると403になる', function () {
    createCinema('gion', '祇園ムビ');

    $admin = createAdmin(AdminRole::CinemaAdmin, null);
    $this->actingAs($admin, 'admin');

    Theater::all();
})->throws(HttpException::class);

/**
 * 上映編成（A-08）も `CinemaScope` の適用対象（4.8.6追記表）。`Theater` と同じ
 * 3つの経路（一覧・ID指定・一括更新）と、所属館未設定時の403を固定する。
 */
function createBookingFor(Cinema $cinema, string $title): Booking
{
    $format = Format::firstOrCreate(['name' => '2D'], ['default_surcharge' => 0]);
    $movie = Movie::create([
        'tmdb_id' => random_int(970_000, 979_999),
        'title' => $title,
        'synopsis' => 'あらすじ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);

    return Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 0,
    ]);
}

it('cinema-admin は自館の上映編成のみ取得できる（T-02）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $own = createBookingFor($gion, '祇園上映作品');
    createBookingFor($kyoto, '京都上映作品');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    $bookings = Booking::all();

    expect($bookings)->toHaveCount(1);
    expect($bookings->first()->id)->toBe($own->id);
});

it('cinema-admin はIDを指定しても他館の上映編成を取得できない（T-02 / 17.2.1）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    createBookingFor($gion, '祇園上映作品');
    $other = createBookingFor($kyoto, '京都上映作品');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    expect(Booking::find($other->id))->toBeNull();
});

it('cinema-admin は他館の上映編成を一括更新できない（T-02）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    createBookingFor($gion, '祇園上映作品');
    $other = createBookingFor($kyoto, '京都上映作品');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    $updated = Booking::where('id', $other->id)->update(['surcharge' => 999]);

    expect($updated)->toBe(0);
    expect($other->fresh()->surcharge)->toBe(0);
});

it('所属館が未設定の cinema-admin が上映編成を取得しようとすると403になる', function () {
    createCinema('gion', '祇園ムビ');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, null), 'admin');

    Booking::all();
})->throws(HttpException::class);
