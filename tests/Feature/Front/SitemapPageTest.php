<?php

/*
 * サイトマップ（P-20、7.1.1 / 4.9.1。工程7-f）。
 * 各館の館トップ・施設案内・アクセスへのリンクがあり、マイページ（会員専用）
 * および予約フロー（上映回IDに依存する。19.3-6）へのリンクを持たないことを確認する。
 */
it('各館の館トップ・施設案内・アクセスへのリンクを表示する', function () {
    $cinema = createCinema('gion', '祇園ムビ');

    $this->get(route('front.sitemap.index'))
        ->assertOk()
        ->assertSee('href="'.route('front.cinema.show', ['slug' => $cinema->slug]).'"', false)
        ->assertSee('href="'.route('front.establishment.index', ['slug' => $cinema->slug]).'"', false)
        ->assertSee('href="'.route('front.access.index', ['slug' => $cinema->slug]).'"', false);
});

it('館非依存ページ（お問い合わせを含む）へのリンクを表示する', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.sitemap.index'))
        ->assertOk()
        ->assertSee('href="'.route('front.contact.index').'"', false);
});

it('マイページと予約フローへのリンクを持たない', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.sitemap.index'))
        ->assertOk()
        ->assertDontSee('href="'.route('front.mypage.index').'"', false)
        ->assertDontSee('/screenings/', false);
});
