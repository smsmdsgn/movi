<?php

use App\Enums\AdminRole;
use App\Livewire\Admin\Movies\Index;
use App\Models\Booking;
use App\Models\Format;
use App\Models\Movie;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.tmdb.api_key' => 'test-key']);
});

it('super-admin は一覧を閲覧でき、TMDBから取り込みボタンが表示される（4.8.2）', function () {
    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.movie.index'))
        ->assertOk()
        ->assertSee(__('admin.movie.actions.import'));
});

it('cinema-admin は一覧を閲覧できるが、取り込み・編集ボタンが表示されず「詳細」が表示される（4.8.2）', function () {
    Movie::create([
        'tmdb_id' => 1000,
        'title' => '作品H',
        'synopsis' => 'あらすじH',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin')
        ->get(route('admin.movie.index'))
        ->assertOk()
        ->assertDontSee(__('admin.movie.actions.import'))
        ->assertDontSee(__('admin.movie.actions.edit'))
        ->assertSee(__('admin.movie.actions.view'));
});

it('gate ロールは一覧を取得できない（4.8.2 / 17.1.3）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('TMDB検索が結果を一覧化し、登録済みの作品には登録済みの印が付く', function () {
    Movie::create([
        'tmdb_id' => 100,
        'title' => '作品A',
        'synopsis' => 'あらすじA',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);

    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [
                ['id' => 100, 'title' => '作品A', 'original_title' => 'Movie A', 'release_date' => '2020-01-01', 'poster_path' => '/a.jpg'],
                ['id' => 200, 'title' => '作品B', 'original_title' => 'Movie B', 'release_date' => '2021-01-01', 'poster_path' => '/b.jpg'],
            ],
        ], 200),
    ]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('openImport')
        ->set('searchQuery', '作品')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSet('searchResults.0.registered', true)
        ->assertSet('searchResults.1.registered', false);
});

it('取り込み→保存で m_movies に作成され、対応規格が m_movie_format に同期される（4.8.5 / 6.6）', function () {
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);

    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response(['results' => [
            ['id' => 300, 'title' => '作品C', 'original_title' => 'Movie C', 'release_date' => '2019-05-01', 'poster_path' => '/c.jpg'],
        ]], 200),
        'api.themoviedb.org/3/movie/300*' => Http::response([
            'id' => 300,
            'title' => '作品C',
            'original_title' => 'Movie C',
            'overview' => 'あらすじC',
            'poster_path' => '/c.jpg',
            'runtime' => 118,
            'release_date' => '2019-05-01',
            'genres' => [['name' => 'ドラマ'], ['name' => 'コメディ']],
        ], 200),
    ]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('openImport')
        ->set('searchQuery', '作品C')
        ->call('search')
        ->call('import', 300)
        ->assertSet('showForm', true)
        ->assertSet('title', '作品C')
        ->assertSet('genres', 'ドラマ, コメディ')
        ->set('format_ids', [$format->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $movie = Movie::where('tmdb_id', 300)->first();

    expect($movie)->not->toBeNull();
    expect($movie->title)->toBe('作品C');
    expect($movie->genres)->toBe(['ドラマ', 'コメディ']);
    expect($movie->formats->pluck('id')->all())->toBe([$format->id]);
});

it('既に登録済みの tmdb_id は取り込めず、作品が二重に作成されない', function () {
    Movie::create([
        'tmdb_id' => 400,
        'title' => '既存作品',
        'synopsis' => 'あらすじ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);

    Http::fake();

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('openImport')
        ->call('import', 400)
        ->assertSet('showForm', false)
        ->assertSet('searchError', __('admin.movie.errors.already_registered'));

    expect(Movie::where('tmdb_id', 400)->count())->toBe(1);
    Http::assertNothingSent();
});

it('公開日が未来の作品は保存できない（4.8.5）', function () {
    Http::fake([
        'api.themoviedb.org/3/movie/600*' => Http::response([
            'id' => 600,
            'title' => '作品D',
            'overview' => 'あらすじD',
            'runtime' => 100,
            'release_date' => now()->subYear()->format('Y-m-d'),
        ], 200),
    ]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('openImport')
        ->call('import', 600)
        ->set('released_on', now()->addYear()->format('Y-m-d'))
        ->call('save')
        ->assertHasErrors(['released_on']);

    expect(Movie::where('tmdb_id', 600)->exists())->toBeFalse();
});

it('poster_path に絶対URLを保存できない（17.5.2-4）', function () {
    Http::fake([
        'api.themoviedb.org/3/movie/700*' => Http::response([
            'id' => 700,
            'title' => '作品E',
            'overview' => 'あらすじE',
            'runtime' => 100,
            'release_date' => now()->subYear()->format('Y-m-d'),
        ], 200),
    ]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('openImport')
        ->call('import', 700)
        ->set('poster_path', 'https://evil.example.com/a.jpg')
        ->call('save')
        ->assertHasErrors(['poster_path']);

    expect(Movie::where('tmdb_id', 700)->exists())->toBeFalse();
});

it('cinema-admin は openImport を実行できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    Livewire::test(Index::class)->call('openImport');
})->throws(AuthorizationException::class);

it('cinema-admin は edit を実行できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $movie = Movie::create([
        'tmdb_id' => 800,
        'title' => '作品F',
        'synopsis' => 'あらすじF',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    Livewire::test(Index::class)->call('edit', $movie->id);
})->throws(AuthorizationException::class);

it('import() を経由せず save() を直接呼んでも何も作成されない（tmdb_idがnullのガード）', function () {
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('title', '不正な作品')
        ->call('save')
        ->assertHasNoErrors();

    expect(Movie::count())->toBe(0);
});

it('TMDB APIキー未設定時は検索がエラーメッセージになり、HTTPリクエストが発生しない', function () {
    config(['services.tmdb.api_key' => null]);
    Http::fake();

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('openImport')
        ->set('searchQuery', '作品')
        ->call('search')
        ->assertSet('searchError', __('admin.movie.errors.tmdb_not_configured'));

    Http::assertNothingSent();
});

it('TMDBが5xxを返した場合は errors.tmdb_request_failed が表示される', function () {
    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response([], 500),
    ]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('openImport')
        ->set('searchQuery', '作品')
        ->call('search')
        ->assertSee(__('admin.movie.errors.tmdb_request_failed'));
});

it('既に上映編成が登録されている規格を対応規格から外せない（6.6）', function () {
    $cinema = createCinema();
    $formatA = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $formatB = Format::create(['name' => 'MOVI GRAND', 'default_surcharge' => 800]);

    $movie = Movie::create([
        'tmdb_id' => 900,
        'title' => '作品I',
        'synopsis' => 'あらすじI',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);
    $movie->formats()->sync([$formatA->id, $formatB->id]);

    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $formatA->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 0,
    ]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('edit', $movie->id)
        ->set('format_ids', [$formatB->id])
        ->call('save')
        ->assertHasErrors(['format_ids']);

    expect($movie->fresh()->formats->pluck('id')->sort()->values()->all())
        ->toBe(collect([$formatA->id, $formatB->id])->sort()->values()->all());
});

it('super-admin は既存の映画を編集して保存できる（4.8.5）', function () {
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $movie = Movie::create([
        'tmdb_id' => 960,
        'title' => '旧タイトル',
        'synopsis' => '旧あらすじ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('edit', $movie->id)
        ->set('title', '新タイトル')
        ->set('synopsis', '新あらすじ')
        ->set('runtime_minutes', 125)
        ->set('genres', 'ドラマ、コメディ')
        ->set('format_ids', [$format->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $movie->refresh();

    expect($movie->title)->toBe('新タイトル');
    expect($movie->runtime_minutes)->toBe(125);
    // 全角読点も区切りとして扱う（'u'修飾子が外れると日本語が壊れる）。
    expect($movie->genres)->toBe(['ドラマ', 'コメディ']);
    expect($movie->formats->pluck('id')->all())->toBe([$format->id]);
    expect($movie->tmdb_id)->toBe(960);
});

it('編集時に自分自身の tmdb_id は重複エラーにならない', function () {
    // Rule::unique()->ignore($this->movie_id) が外れると、編集のたびに
    // 自分自身と衝突して保存できなくなる。
    $movie = Movie::create([
        'tmdb_id' => 970,
        'title' => '作品K',
        'synopsis' => 'あらすじK',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('edit', $movie->id)
        ->set('title', '作品K（改訂）')
        ->call('save')
        ->assertHasNoErrors();

    expect($movie->fresh()->title)->toBe('作品K（改訂）');
});

it('cinema-admin は view() で開いた後に save() を呼んでも更新できない（4.8.2）', function () {
    // view() は movie_id / tmdb_id（#[Locked]）を確定させるため、cinema-admin が
    // save() の早期リターンを通過して認可判定に到達できる唯一の経路。
    $this->withoutExceptionHandling();
    $movie = Movie::create([
        'tmdb_id' => 980,
        'title' => '作品L',
        'synopsis' => 'あらすじL',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    Livewire::test(Index::class)
        ->call('view', $movie->id)
        ->set('title', '改ざんされたタイトル')
        ->call('save');
})->throws(AuthorizationException::class);

it('cinema-admin は view() であらすじを含む全項目を閲覧できる（4.8.2）', function () {
    $movie = Movie::create([
        'tmdb_id' => 950,
        'title' => '作品J',
        'synopsis' => '詳細なあらすじJJJ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    Livewire::test(Index::class)
        ->call('view', $movie->id)
        ->assertSet('readOnly', true)
        ->assertSet('synopsis', '詳細なあらすじJJJ')
        ->assertSee('詳細なあらすじJJJ');
});
