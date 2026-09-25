<?php

use App\Models\User;

it('館別ページでは劇場切替が同種のページのURLになる（4.1.3-4）', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->get(route('front.schedule.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee(route('front.schedule.index', ['slug' => 'kyoto']), false);
});

it('館別ページの劇場切替は slug 以外のURIパラメータを引き継ぐ（P-25 のカテゴリー）', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');
    createPostCategory('campaign', 'キャンペーン');

    $this->get(route('front.news.category', ['slug' => 'gion', 'category' => 'campaign']))
        ->assertOk()
        ->assertSee('value="'.route('front.news.category', ['slug' => 'kyoto', 'category' => 'campaign']).'"', false);
});

it('館別ページの劇場切替は defaults() 由来のパラメータをURLに付けない（4.1.3追記表）', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->get(route('front.establishment.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('value="'.route('front.establishment.index', ['slug' => 'kyoto']).'"', false)
        ->assertDontSee('screenId=', false);
});

it('館非依存ページでは劇場切替が切替先の館トップのURLになる', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee(route('front.cinema.show', ['slug' => 'kyoto']), false);
});

it('ヘッダーの劇場切替が、全館共通の記事は同じ記事詳細へ、特定館の記事は切替先の館トップへリンクする（P-26）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $shared = createPost(['cinema_id' => null]);
    $ownOnly = createPost(['cinema_id' => $cinema->id]);

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $shared->id]))
        ->assertOk()
        ->assertSee('value="'.route('front.news.show', ['slug' => 'kyoto', 'id' => $shared->id]).'"', false);

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $ownOnly->id]))
        ->assertOk()
        ->assertSee('value="'.route('front.cinema.show', ['slug' => 'kyoto']).'"', false);
});

it('未ログイン時はヘッダーのマイページ相当のリンクがログイン画面になる', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.home'))
        ->assertOk()
        ->assertSee('href="'.route('login').'"', false);
});

it('ログイン時はヘッダーのマイページリンクがマイページになる', function () {
    createCinema('gion', '祇園ムビ');

    $this->actingAs(User::factory()->create())
        ->get(route('front.home'))
        ->assertOk()
        ->assertSee('href="'.route('front.mypage.index').'"', false);
});
