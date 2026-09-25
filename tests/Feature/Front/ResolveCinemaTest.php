<?php

use App\Http\Middleware\SkipCinemaScope;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Theater;
use Illuminate\Support\Facades\Route;

it('routes/ で定義したルートのアクションにクロージャを使用しない', function () {
    /*
     * route:cache（15.3.2）はクロージャを直列化する際に入れ子の外側を選ぶことがあり、
     * コマンド自体は成功したまま本番でのみ壊れたルートを生成する。
     * テストは非キャッシュで走るため、この形でしか検出できない。
     * ベンダー（Livewire・health・storage 等）のクロージャは定義元ファイルで除外する。
     */
    $closureRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->getAction('uses') instanceof Closure)
        ->filter(function ($route) {
            $file = (new ReflectionFunction($route->getAction('uses')))->getFileName();

            return is_string($file) && str_starts_with($file, base_path('routes'));
        })
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($closureRoutes)->toBeEmpty();
});

it('館別ページで {slug} の館を解決し、画面IDに対応するページを返す', function (string $routeName, string $screenId, array $parameters) {
    createCinema('gion', '祇園ムビ');

    /*
     * ヘッダーの劇場切替セレクトボックスにも館名が候補として出るため、
     * 解決された館を表す data-testid="cinema-name" の内容で判定する。
     */
    $this->get(route($routeName, ['slug' => 'gion', ...$parameters]))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">祇園ムビ<', false)
        ->assertSee($screenId);
})->with([
    'P-27 施設案内' => ['front.establishment.index', 'P-27', []],
    'P-28 アクセス' => ['front.access.index', 'P-28', []],
]);

it('P-21〜P-23 は画面IDを表示せず、解決された館名（data-testid="cinema-name"）を表示する（工程4で実装）', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">祇園ムビ<', false);
});

it('P-22 上映スケジュールが解決された館名を表示する', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.schedule.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">祇園ムビ<', false);
});

it('P-23 作品詳細が解決された館名を表示する', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $theater = Theater::create(['cinema_id' => $cinema->id, 'number' => 1, 'name' => '1番シアター']);
    $format = Format::firstOrCreate(['name' => '2D'], ['default_surcharge' => 0]);
    $movie = Movie::create([
        'tmdb_id' => random_int(1, 899_999_999),
        'title' => 'テスト作品',
        'synopsis' => 'あらすじ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);
    Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now()->subDays(5),
        'ends_on' => now()->addDays(5),
        'surcharge' => 0,
    ]);

    $this->get(route('front.movie.show', ['slug' => 'gion', 'id' => $movie->id]))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">祇園ムビ<', false);
});

it('P-24 お知らせ一覧が解決された館名を表示する', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.news.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">祇園ムビ<', false)
        ->assertDontSee('P-24');
});

it('P-25 お知らせカテゴリー別が解決された館名を表示する', function () {
    createCinema('gion', '祇園ムビ');
    createPostCategory('campaign', 'キャンペーン');

    $this->get(route('front.news.category', ['slug' => 'gion', 'category' => 'campaign']))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">祇園ムビ<', false)
        ->assertDontSee('P-25');
});

it('P-26 お知らせ詳細が解決された館名を表示する', function () {
    createCinema('gion', '祇園ムビ');
    $post = createPost();

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $post->id]))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">祇園ムビ<', false)
        ->assertDontSee('P-26');
});

it('存在しない slug では404を返す', function () {
    createCinema('gion', '祇園ムビ');

    $this->get('/cinemas/nonexistent')->assertNotFound();
});

it('4.1.3-6 の形式に反する slug は、該当する館が存在してもルートに一致しない', function (string $requested, string $existing) {
    createCinema($existing, '祇園ムビ');

    $this->get("/cinemas/{$requested}")->assertNotFound();
})->with([
    // MariaDB の既定照合順序は大文字小文字を区別しないため、制約が無ければ gion に一致する
    '大文字' => ['GION', 'gion'],
    '数字' => ['gion2', 'gion2'],
    'アンダースコア' => ['gion_kyoto', 'gion_kyoto'],
    '先頭のハイフン' => ['-gion', '-gion'],
    '連続したハイフン' => ['gion--kyoto', 'gion--kyoto'],
]);

it('slug ごとに異なる館を解決する', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    /*
     * ヘッダーの劇場切替セレクトボックス（本タスクで追加）に他館の名称が
     * 選択肢として表示されるため、ページ全体に「祇園ムビ」が無いことは検証できない。
     * 解決された館を表す data-testid="cinema-name" の内容で判定する。
     */
    $this->get(route('front.cinema.show', ['slug' => 'kyoto']))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">ムビ京都<', false)
        ->assertDontSee('data-testid="cinema-name">祇園ムビ<', false);
});

it('解決した館をコンテナ経由でコントローラへ引き渡す', function () {
    /* ルートに {cinema} が無いため、コントローラの Cinema はコンテナからしか解決されない。 */
    $cinema = createCinema('gion', '祇園ムビ');

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertViewHas('cinema', fn (Cinema $rendered) => $rendered->is($cinema));
});

it('顧客向けルート（front.*）にはすべて SkipCinemaScope が付いている（13.4.1）', function () {
    /*
     * 管理者のセッションが残ったブラウザで顧客側の館スコープが効いてしまう不具合
     * （4.2.3追記表）は、ルートをグループの外に書いた時点で再発する。
     * routes/web.php に追加した顧客向けルートが漏れなくグループ内にあることを固定する。
     */
    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'front.'))
        ->reject(fn ($route) => in_array(SkipCinemaScope::class, $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName())
        ->values();

    expect($missing)->toBeEmpty();
});
