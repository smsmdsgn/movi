<?php

use App\Models\Cinema;
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

    $this->get(route($routeName, ['slug' => 'gion', ...$parameters]))
        ->assertOk()
        ->assertSee('祇園ムビ')
        ->assertSee($screenId);
})->with([
    'P-21 館トップ' => ['front.cinema.show', 'P-21', []],
    'P-22 上映スケジュール' => ['front.schedule.index', 'P-22', []],
    'P-23 作品詳細' => ['front.movie.show', 'P-23', ['id' => 1]],
    'P-24 お知らせ一覧' => ['front.news.index', 'P-24', []],
    'P-25 お知らせカテゴリー別' => ['front.news.category', 'P-25', ['category' => 'campaign']],
    'P-26 お知らせ詳細' => ['front.news.show', 'P-26', ['id' => 1]],
    'P-27 施設案内' => ['front.establishment.index', 'P-27', []],
    'P-28 アクセス' => ['front.access.index', 'P-28', []],
]);

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

    $this->get(route('front.cinema.show', ['slug' => 'kyoto']))
        ->assertOk()
        ->assertSee('ムビ京都')
        ->assertDontSee('祇園ムビ');
});

it('解決した館をコンテナ経由でコントローラへ引き渡す', function () {
    /* ルートに {cinema} が無いため、コントローラの Cinema はコンテナからしか解決されない。 */
    $cinema = createCinema('gion', '祇園ムビ');

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertViewHas('cinema', fn (Cinema $rendered) => $rendered->is($cinema));
});
