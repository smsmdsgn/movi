<?php

use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\Theater;
use Carbon\CarbonImmutable;

/**
 * 作品詳細（P-23）テスト用の作品・上映編成の最小フィクスチャ。
 *
 * @return array{cinema: Cinema, theater: Theater, format: Format, movie: Movie, booking: Booking}
 */
function makeMovieDetailFixture(array $movieOverrides = [], array $bookingOverrides = []): array
{
    $cinema = createCinema();
    $theater = Theater::create(['cinema_id' => $cinema->id, 'number' => 1, 'name' => '1番シアター']);
    $format = Format::firstOrCreate(['name' => '字幕版'], ['default_surcharge' => 0]);

    $movie = Movie::create(array_merge([
        'tmdb_id' => random_int(1, 899_999_999),
        'title' => 'テスト作品',
        'original_title' => 'Test Movie',
        'synopsis' => "あらすじの1行目\nあらすじの2行目",
        'runtime_minutes' => 128,
        'released_on' => CarbonImmutable::parse('2024-05-01'),
        'genres' => ['アクション', 'コメディ'],
    ], $movieOverrides));
    $movie->formats()->sync([$format->id]);

    $booking = Booking::create(array_merge([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now()->subDays(5),
        'ends_on' => now()->addDays(5),
        'surcharge' => 0,
    ], $bookingOverrides));

    return compact('cinema', 'theater', 'format', 'movie', 'booking');
}

it('作品タイトル・原題・公開年・上映時間・ジャンル・あらすじ・対応規格・上映期間が表示される', function () {
    $fixture = makeMovieDetailFixture();
    $cinema = $fixture['cinema'];
    $movie = $fixture['movie'];

    $this->get(route('front.movie.show', ['slug' => $cinema->slug, 'id' => $movie->id]))
        ->assertOk()
        ->assertSee($movie->title)
        ->assertSee($movie->original_title)
        ->assertSee('2024年')
        ->assertSee('128分')
        ->assertSee('アクション・コメディ')
        ->assertSee('あらすじの1行目')
        ->assertSee('字幕版')
        ->assertSee($fixture['booking']->starts_on->format('Y/n/j'));
});

it('この館に上映編成の無い作品は404、存在しないidも404', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $other = makeMovieDetailFixture();

    $this->get(route('front.movie.show', ['slug' => 'gion', 'id' => $other['movie']->id]))->assertNotFound();
    $this->get(route('front.movie.show', ['slug' => 'gion', 'id' => 999999]))->assertNotFound();
});

it('上映終了済み（過去の編成のみ）の作品は200を返す', function () {
    $fixture = makeMovieDetailFixture(bookingOverrides: [
        'starts_on' => now()->subDays(20),
        'ends_on' => now()->subDays(10),
    ]);

    $this->get(route('front.movie.show', ['slug' => $fixture['cinema']->slug, 'id' => $fixture['movie']->id]))
        ->assertOk();
});

it('埋め込みのスケジュール表がこの作品の回のみを含む', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00:00'));

    $fixture = makeMovieDetailFixture();
    $cinema = $fixture['cinema'];
    $theater = $fixture['theater'];

    Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $theater->id,
        'starts_at' => CarbonImmutable::parse('2026-01-10 14:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-01-10 16:00:00'),
    ]);

    // 同日同館の別作品の上映回。
    $otherMovie = Movie::create([
        'tmdb_id' => random_int(1, 899_999_999),
        'title' => '別の作品',
        'synopsis' => 'あらすじ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);
    $otherBooking = Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $otherMovie->id,
        'format_id' => $fixture['format']->id,
        'starts_on' => now()->subDays(5),
        'ends_on' => now()->addDays(5),
        'surcharge' => 0,
    ]);
    Screening::create([
        'booking_id' => $otherBooking->id,
        'theater_id' => $theater->id,
        'starts_at' => CarbonImmutable::parse('2026-01-10 18:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-01-10 20:00:00'),
    ]);

    $this->get(route('front.movie.show', ['slug' => $cinema->slug, 'id' => $fixture['movie']->id]))
        ->assertOk()
        ->assertSee('14:00')
        ->assertDontSee('18:00');
});

it('ヘッダーの劇場切替が、作品を上映する館へは作品詳細、上映しない館へは館トップへリンクする', function () {
    $fixture = makeMovieDetailFixture();
    $cinema = $fixture['cinema'];
    $movie = $fixture['movie'];

    $showingCinema = createCinema('kyoto', 'ムビ京都');
    Booking::create([
        'cinema_id' => $showingCinema->id,
        'movie_id' => $movie->id,
        'format_id' => $fixture['format']->id,
        'surcharge' => 0,
        'starts_on' => now()->subDay(),
        'ends_on' => now()->addDays(10),
    ]);
    $notShowingCinema = createCinema('osaka', 'ムビ大阪');

    $this->get(route('front.movie.show', ['slug' => $cinema->slug, 'id' => $movie->id]))
        ->assertOk()
        ->assertSee('value="'.route('front.movie.show', ['slug' => 'kyoto', 'id' => $movie->id]).'"', false)
        ->assertSee('value="'.route('front.cinema.show', ['slug' => 'osaka']).'"', false);
});

it('hasTmdbPage()が真ならTMDBリンクがrel=noopener noreferrer付きで表示される', function () {
    $withTmdb = makeMovieDetailFixture(['tmdb_id' => 550]);

    $this->get(route('front.movie.show', ['slug' => $withTmdb['cinema']->slug, 'id' => $withTmdb['movie']->id]))
        ->assertOk()
        ->assertSee('rel="noopener noreferrer"', false)
        ->assertSee('href="https://www.themoviedb.org/movie/550"', false);
});

it('hasTmdbPage()が偽の場合はTMDBリンクを表示しない', function () {
    $withoutTmdb = makeMovieDetailFixture(['tmdb_id' => Movie::TMDB_ID_DUMMY_MIN + 1]);

    $this->get(route('front.movie.show', ['slug' => $withoutTmdb['cinema']->slug, 'id' => $withoutTmdb['movie']->id]))
        ->assertOk()
        ->assertDontSee('themoviedb.org');
});

it('titleが「{作品名}｜{館名}｜MOVI」で、MovieのJSON-LDとog:imageを含む', function () {
    $fixture = makeMovieDetailFixture(['title' => 'サンプル作品', 'poster_path' => '/sample-poster.jpg']);
    $cinema = $fixture['cinema'];
    $movie = $fixture['movie'];

    $this->get(route('front.movie.show', ['slug' => $cinema->slug, 'id' => $movie->id]))
        ->assertOk()
        ->assertSee('<title>サンプル作品｜'.$cinema->name.'｜MOVI</title>', false)
        ->assertSee('"@type":"Movie"', false)
        ->assertSee('property="og:image" content="https://image.tmdb.org/t/p/w342/sample-poster.jpg"', false);
});
