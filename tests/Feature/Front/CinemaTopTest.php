<?php

use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use Carbon\CarbonImmutable;

/**
 * 館トップ（P-21、7.3）の作品一覧タブ用に、上映編成を1件作成する。
 */
function makeCinemaTopBooking(Cinema $cinema, string $title, CarbonImmutable $startsOn, CarbonImmutable $endsOn): Booking
{
    $format = Format::firstOrCreate(['name' => '2D'], ['default_surcharge' => 0]);

    $movie = Movie::create([
        'tmdb_id' => random_int(1, 999_999_999),
        'title' => $title,
        'synopsis' => 'あらすじ',
        'runtime_minutes' => 100,
        'released_on' => $startsOn->subYear(),
    ]);

    return Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'surcharge' => 0,
    ]);
}

it('上映開始日・終了日に応じて作品を上映中・公開予定・上映終了のタブへ振り分ける（4.2.1、境界値）', function () {
    $today = CarbonImmutable::parse('2026-01-10 00:00:00');
    $this->travelTo($today);

    $cinema = createCinema('gion', '祇園ムビ');

    makeCinemaTopBooking($cinema, '当日開始作品', $today, $today->addDays(10));
    makeCinemaTopBooking($cinema, '当日終了作品', $today->subDays(10), $today);
    makeCinemaTopBooking($cinema, '公開予定作品', $today->addDay(), $today->addDays(20));
    makeCinemaTopBooking($cinema, '上映終了作品', $today->subDays(20), $today->subDay());

    $html = $this->get(route('front.cinema.show', ['slug' => 'gion']))->assertOk()->getContent();

    $posNow = mb_strpos($html, 'data-tab="now"');
    $posUpcoming = mb_strpos($html, 'data-tab="upcoming"');
    $posEnded = mb_strpos($html, 'data-tab="ended"');

    expect($posNow)->not->toBeFalse();
    expect($posUpcoming)->not->toBeFalse();
    expect($posEnded)->not->toBeFalse();

    $posStartToday = mb_strpos($html, '当日開始作品');
    $posEndToday = mb_strpos($html, '当日終了作品');
    $posUpcomingMovie = mb_strpos($html, '公開予定作品');
    $posEndedMovie = mb_strpos($html, '上映終了作品');

    // 上映中タブ（now〜upcomingの間）に入る。
    expect($posStartToday)->toBeGreaterThan($posNow)->toBeLessThan($posUpcoming);
    expect($posEndToday)->toBeGreaterThan($posNow)->toBeLessThan($posUpcoming);

    // 公開予定タブ（upcoming〜endedの間）に入る。
    expect($posUpcomingMovie)->toBeGreaterThan($posUpcoming)->toBeLessThan($posEnded);

    // 上映終了タブ（ended以降）に入る。
    expect($posEndedMovie)->toBeGreaterThan($posEnded);
});

it('他館の上映編成の作品は表示されない', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 00:00:00'));

    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    makeCinemaTopBooking($kyoto, '京都限定作品', now(), now()->addWeek());

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertDontSee('京都限定作品');
});

it('同一作品に上映中と上映終了の編成がある場合、上映中タブにのみ入る', function () {
    $today = CarbonImmutable::parse('2026-01-10 00:00:00');
    $this->travelTo($today);

    $cinema = createCinema('gion', '祇園ムビ');
    $format = Format::firstOrCreate(['name' => '2D'], ['default_surcharge' => 0]);
    $movie = Movie::create([
        'tmdb_id' => random_int(1, 999_999_999),
        'title' => '再上映作品',
        'synopsis' => 'あらすじ',
        'runtime_minutes' => 100,
        'released_on' => $today->subYears(2),
    ]);

    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => $today->subDays(20),
        'ends_on' => $today->subDays(10),
        'surcharge' => 0,
    ]);
    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => $today,
        'ends_on' => $today->addDays(10),
        'surcharge' => 0,
    ]);

    $html = $this->get(route('front.cinema.show', ['slug' => 'gion']))->assertOk()->getContent();

    $posNow = mb_strpos($html, 'data-tab="now"');
    $posUpcoming = mb_strpos($html, 'data-tab="upcoming"');
    $posEnded = mb_strpos($html, 'data-tab="ended"');

    // タイトルはポスターの代替表示とリンクの2箇所に出るため、出現位置がすべて
    // 上映中タブ（now〜upcomingの間）に収まっていることを検証する。
    preg_match_all('/再上映作品/u', $html, $matches, PREG_OFFSET_CAPTURE);
    $positions = array_column($matches[0], 1);

    expect($positions)->not->toBeEmpty();
    expect(min($positions))->toBeGreaterThan($posNow);
    expect(max($positions))->toBeLessThan($posUpcoming);
    expect($posEnded)->toBeGreaterThan($posUpcoming);
});

it('titleとcanonicalとMovieTheaterのJSON-LDを含む', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('<title>祇園ムビ｜MOVI</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('front.cinema.show', ['slug' => 'gion']).'">', false)
        ->assertSee('"@type":"MovieTheater"', false);
});

it('作品カードが作品詳細ページへリンクする', function () {
    $today = CarbonImmutable::parse('2026-01-10 00:00:00');
    $this->travelTo($today);

    $cinema = createCinema('gion', '祇園ムビ');
    $booking = makeCinemaTopBooking($cinema, '上映中作品', $today, $today->addDays(10));

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('href="'.route('front.movie.show', ['slug' => 'gion', 'id' => $booking->movie_id]).'"', false);
});

it('離れた再上映がある作品は、区分に該当する編成の期間だけを表示する（4.2.3追記表）', function () {
    $today = CarbonImmutable::parse('2026-09-06 00:00:00');
    $this->travelTo($today);

    $cinema = createCinema('gion', '祇園ムビ');

    // 6月の旧編成（上映終了）と12月の再上映（公開予定）。全編成の最小〜最大に丸めると「6/1 〜 12/31」になる。
    $ended = makeCinemaTopBooking($cinema, '再上映作品', CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'));
    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $ended->movie_id,
        'format_id' => $ended->format_id,
        'starts_on' => CarbonImmutable::parse('2026-12-01'),
        'ends_on' => CarbonImmutable::parse('2026-12-31'),
        'surcharge' => 0,
    ]);

    $html = $this->get(route('front.cinema.show', ['slug' => 'gion']))->assertOk()->getContent();

    $posUpcoming = mb_strpos($html, 'data-tab="upcoming"');
    $posEnded = mb_strpos($html, 'data-tab="ended"');
    $posTitle = mb_strpos($html, '再上映作品');

    expect($posTitle)->toBeGreaterThan($posUpcoming)->toBeLessThan($posEnded);
    expect($html)->toContain('2026/12/1 〜 2026/12/31');
    expect($html)->not->toContain('2026/6/1 〜 2026/12/31');
});
