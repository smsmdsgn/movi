<?php

use App\Models\User;

it('館別ページでは劇場切替が同種のページのURLになる（4.1.3-4）', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->get(route('front.schedule.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee(route('front.schedule.index', ['slug' => 'kyoto']), false);
});

it('館非依存ページでは劇場切替が切替先の館トップのURLになる', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee(route('front.cinema.show', ['slug' => 'kyoto']), false);
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
