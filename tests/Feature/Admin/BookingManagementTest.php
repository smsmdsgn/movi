<?php

use App\Enums\AdminRole;
use App\Livewire\Admin\Bookings\Index;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\Theater;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/**
 * 上映編成（A-08）に対応する規格を持つ映画を1件作成する（4.8.6追記表「A-08で選択できる
 * 上映規格」）。`m_movie_format` を経由してのみ規格判定に反映されるため `sync()` する。
 *
 * @param  array<int, Format>  $formats
 */
function makeMovieWithFormats(array $formats, string $title = 'テスト作品'): Movie
{
    static $tmdbId = 980_000;

    $movie = Movie::create([
        'tmdb_id' => ++$tmdbId,
        'title' => $title,
        'synopsis' => 'テスト用のあらすじ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);
    $movie->formats()->sync(collect($formats)->pluck('id')->all());

    return $movie;
}

/**
 * 指定した館の下に、指定の規格に対応するシアターを1件作成する（6.6「劇場側はいずれかの
 * シアターが対応する」の判定対象、4.8.6追記表）。
 *
 * @param  array<int, Format>  $formats
 */
function makeTheaterWithFormats(Cinema $cinema, array $formats): Theater
{
    $theater = Theater::create(['cinema_id' => $cinema->id, 'number' => 1, 'name' => '1番シアター']);
    $theater->formats()->sync(collect($formats)->pluck('id')->all());

    return $theater;
}

it('super-admin は一覧を閲覧でき、新規登録ボタンが表示される（4.8.2）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 0,
    ]);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.booking.index'))
        ->assertOk()
        ->assertSee($movie->title)
        ->assertSee(__('admin.booking.actions.create'));
});

it('cinema-admin は一覧を閲覧できるが、新規登録・編集ボタンが表示されない（4.8.2）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 0,
    ]);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $cinema), 'admin')
        ->get(route('admin.booking.index'))
        ->assertOk()
        ->assertSee($movie->title)
        ->assertDontSee(__('admin.booking.actions.create'))
        ->assertDontSee(__('admin.booking.actions.edit'));
});

it('cinema-admin には自館の上映編成しか見えない（13.4.1 CinemaScope）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($gion, [$format]);
    makeTheaterWithFormats($kyoto, [$format]);
    $gionMovie = makeMovieWithFormats([$format], '祇園上映作品');
    $kyotoMovie = makeMovieWithFormats([$format], '京都上映作品');
    Booking::create([
        'cinema_id' => $gion->id,
        'movie_id' => $gionMovie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 0,
    ]);
    Booking::create([
        'cinema_id' => $kyoto->id,
        'movie_id' => $kyotoMovie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 0,
    ]);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin')
        ->get(route('admin.booking.index'))
        ->assertOk()
        ->assertSee('祇園上映作品')
        ->assertDontSee('京都上映作品');
});

it('gate ロールは一覧を取得できない（4.8.2 / 17.1.3）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('super-admin は上映編成を新規登録できる（作品・館の双方が対応する規格で、4.8.6追記表）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $format->id)
        ->set('starts_on', now()->format('Y-m-d'))
        ->set('ends_on', now()->addWeek()->format('Y-m-d'))
        ->set('surcharge', '0')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect(Booking::where('movie_id', $movie->id)->exists())->toBeTrue();
});

it('cinema-admin は createBooking / editBooking / save を実行できない（4.8.2）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $booking = Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 0,
    ]);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $cinema), 'admin');

    $this->withoutExceptionHandling();
    expect(fn () => Livewire::test(Index::class)->call('createBooking'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => Livewire::test(Index::class)->call('editBooking', $booking->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => Livewire::test(Index::class)->call('save'))
        ->toThrow(AuthorizationException::class);
});

it('作品が対応しない規格では保存できず、format_id にエラーが付く（6.6 / 4.8.6追記表）', function () {
    $cinema = createCinema();
    $formatA = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $formatB = Format::create(['name' => 'MOVI GRAND', 'default_surcharge' => 800]);
    makeTheaterWithFormats($cinema, [$formatA, $formatB]);
    $movie = makeMovieWithFormats([$formatA]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $formatB->id)
        ->set('starts_on', now()->format('Y-m-d'))
        ->set('ends_on', now()->addWeek()->format('Y-m-d'))
        ->set('surcharge', '0')
        ->call('save')
        ->assertHasErrors(['format_id']);

    expect(Booking::where('movie_id', $movie->id)->exists())->toBeFalse();
});

it('館のどのシアターも対応しない規格では保存できず、format_id にエラーが付く（6.6 / 4.8.6追記表）', function () {
    $cinema = createCinema();
    $formatA = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $formatB = Format::create(['name' => 'MOVI GRAND', 'default_surcharge' => 800]);
    makeTheaterWithFormats($cinema, [$formatA]);
    $movie = makeMovieWithFormats([$formatA, $formatB]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $formatB->id)
        ->set('starts_on', now()->format('Y-m-d'))
        ->set('ends_on', now()->addWeek()->format('Y-m-d'))
        ->set('surcharge', '0')
        ->call('save')
        ->assertHasErrors(['format_id']);

    expect(Booking::where('movie_id', $movie->id)->exists())->toBeFalse();
});

it('同一館・同一作品・同一規格で期間が重なる編成は保存できず、starts_on にエラーが付く（4.8.6追記表）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addMonth(),
        'surcharge' => 0,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $format->id)
        ->set('starts_on', now()->addWeeks(2)->format('Y-m-d'))
        ->set('ends_on', now()->addMonths(2)->format('Y-m-d'))
        ->set('surcharge', '0')
        ->call('save')
        ->assertHasErrors(['starts_on']);

    expect(Booking::where('movie_id', $movie->id)->count())->toBe(1);
});

it('作品が異なれば同一期間の編成を登録できる（重複禁止は同一館・同一作品・同一規格の組に限る）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie1 = makeMovieWithFormats([$format], '作品1');
    $movie2 = makeMovieWithFormats([$format], '作品2');
    $startsOn = now();
    $endsOn = now()->addMonth();
    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie1->id,
        'format_id' => $format->id,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'surcharge' => 0,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie2->id)
        ->set('format_id', (string) $format->id)
        ->set('starts_on', $startsOn->format('Y-m-d'))
        ->set('ends_on', $endsOn->format('Y-m-d'))
        ->set('surcharge', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect(Booking::where('movie_id', $movie2->id)->exists())->toBeTrue();
});

it('重複判定の境界: 終了日の翌日から始まる編成は登録できるが、終了日と同じ日から始まる編成は登録できない', function (int $offsetDays, bool $expectError) {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $endsOn = now()->addWeek()->startOfDay();
    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now()->startOfDay(),
        'ends_on' => $endsOn,
        'surcharge' => 0,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    $component = Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $format->id)
        ->set('starts_on', $endsOn->copy()->addDays($offsetDays)->format('Y-m-d'))
        ->set('ends_on', $endsOn->copy()->addDays($offsetDays + 7)->format('Y-m-d'))
        ->set('surcharge', '0')
        ->call('save');

    $expectError ? $component->assertHasErrors(['starts_on']) : $component->assertHasNoErrors();

    expect(Booking::where('movie_id', $movie->id)->count())->toBe($expectError ? 1 : 2);
})->with([
    '終了日と同じ日から（重なる）' => [0, true],
    '終了日の翌日から（重ならない）' => [1, false],
]);

it('editBooking は手動で上書きされた追加料金を保持する（6.5.3-2）', function () {
    // updatedFormatId の既定額流し込みが、編集で開いた既存の値を壊さないこと。
    $cinema = createCinema();
    $format = Format::create(['name' => 'MOVI GRAND', 'default_surcharge' => 800]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $booking = Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 500,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editBooking', $booking->id)
        ->assertSet('surcharge', '500');
});

it('自分自身との重複は無視して編集できる（既存編成の追加料金だけを変えて保存できる）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $booking = Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addMonth(),
        'surcharge' => 0,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editBooking', $booking->id)
        ->set('surcharge', '800')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect($booking->fresh()->surcharge)->toBe(800);
});

it('上映回がある編成は作品・規格・館を変更できず、movie_id にエラーが付く（4.8.6追記表）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $theater = makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $otherMovie = makeMovieWithFormats([$format], '別の作品');
    $booking = Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addMonth(),
        'surcharge' => 0,
    ]);
    Screening::create([
        'booking_id' => $booking->id,
        'theater_id' => $theater->id,
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5)->addHours(2),
    ]);
    $this->actingAs(createAdmin(), 'admin');

    // 作品を変えると規格と追加料金が落ちる（updatedMovieId）ため入れ直す。
    // 入れ直さないと required のエラーが先に立ち、編集制限の判定まで到達しない。
    Livewire::test(Index::class)
        ->call('editBooking', $booking->id)
        ->set('movie_id', (string) $otherMovie->id)
        ->set('format_id', (string) $format->id)
        ->set('surcharge', '0')
        ->call('save')
        ->assertHasErrors(['movie_id']);

    expect($booking->fresh()->movie_id)->toBe($movie->id);
});

it('上映回がある編成の期間を、既存の上映回が範囲外になるように縮めると starts_on にエラーが付く（4.8.3-1）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $theater = makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $startsOn = now()->startOfDay();
    $endsOn = now()->addMonth()->startOfDay();
    $booking = Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'surcharge' => 0,
    ]);
    $screeningDate = $startsOn->addDays(5);
    Screening::create([
        'booking_id' => $booking->id,
        'theater_id' => $theater->id,
        'starts_at' => $screeningDate,
        'ends_at' => $screeningDate->addHours(2),
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editBooking', $booking->id)
        ->set('starts_on', $startsOn->addDays(10)->format('Y-m-d'))
        ->call('save')
        ->assertHasErrors(['starts_on']);

    expect($booking->fresh()->starts_on->toDateString())->toBe($startsOn->toDateString());
});

it('上映回が範囲内に収まる期間の変更はできる（4.8.3-1）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $theater = makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $startsOn = now()->startOfDay();
    $endsOn = now()->addMonth()->startOfDay();
    $booking = Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'surcharge' => 0,
    ]);
    $screeningDate = $startsOn->addDays(5);
    Screening::create([
        'booking_id' => $booking->id,
        'theater_id' => $theater->id,
        'starts_at' => $screeningDate,
        'ends_at' => $screeningDate->addHours(2),
    ]);
    $this->actingAs(createAdmin(), 'admin');

    $newEndsOn = $startsOn->addDays(10);

    Livewire::test(Index::class)
        ->call('editBooking', $booking->id)
        ->set('ends_on', $newEndsOn->format('Y-m-d'))
        ->call('save')
        ->assertHasNoErrors();

    expect($booking->fresh()->ends_on->toDateString())->toBe($newEndsOn->toDateString());
});

it('ends_on が starts_on より前だとバリデーションエラーになる', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $format->id)
        ->set('starts_on', now()->format('Y-m-d'))
        ->set('ends_on', now()->subDay()->format('Y-m-d'))
        ->set('surcharge', '0')
        ->call('save')
        ->assertHasErrors(['ends_on']);
});

it('surcharge の境界値（0円・10,000円）は保存できる（4.8.6追記表）', function (int $surcharge) {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $format->id)
        ->set('starts_on', now()->format('Y-m-d'))
        ->set('ends_on', now()->addWeek()->format('Y-m-d'))
        ->set('surcharge', (string) $surcharge)
        ->call('save')
        ->assertHasNoErrors();

    expect(Booking::where('movie_id', $movie->id)->first()->surcharge)->toBe($surcharge);
})->with([
    '下限（0円）' => 0,
    '上限（10,000円）' => 10000,
]);

it('surcharge が上限を超える場合はバリデーションエラーになる（4.8.6追記表）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $format->id)
        ->set('starts_on', now()->format('Y-m-d'))
        ->set('ends_on', now()->addWeek()->format('Y-m-d'))
        ->set('surcharge', '10001')
        ->call('save')
        ->assertHasErrors(['surcharge']);

    expect(Booking::where('movie_id', $movie->id)->exists())->toBeFalse();
});

it('規格を選び直すと追加料金にその規格の既定額が入る（6.5.3-2）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => 'MOVI GRAND', 'default_surcharge' => 800]);
    makeTheaterWithFormats($cinema, [$format]);
    $movie = makeMovieWithFormats([$format]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('createBooking')
        ->set('cinema_id', (string) $cinema->id)
        ->set('movie_id', (string) $movie->id)
        ->set('format_id', (string) $format->id)
        ->assertSet('surcharge', '800');
});

it('一覧は20件ごとにページングされる（A-05に倣う、4.8.6追記表）', function () {
    $cinema = createCinema();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    makeTheaterWithFormats($cinema, [$format]);

    foreach (range(1, 21) as $i) {
        $movie = makeMovieWithFormats([$format], "作品{$i}");
        Booking::create([
            'cinema_id' => $cinema->id,
            'movie_id' => $movie->id,
            'format_id' => $format->id,
            'starts_on' => now()->addDays($i),
            'ends_on' => now()->addDays($i)->addWeek(),
            'surcharge' => 0,
        ]);
    }

    $this->actingAs(createAdmin(), 'admin');

    $component = Livewire::test(Index::class);

    expect($component->viewData('bookings')->count())->toBe(20);
});
