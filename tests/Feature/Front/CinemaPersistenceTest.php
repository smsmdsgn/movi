<?php

use App\Models\Cinema;

/*
 * 各テストは1リクエストのみ発行する。`CurrentCinemaService` は解決結果を
 * `app()->instance()` でコンテナへバインドし、これはリクエスト境界で破棄されない。
 * 1テスト内で複数回 `$this->get()` を呼ぶと、後続のリクエストが前回の解決結果を
 * セッションの中身に関係なく再利用してしまう（本番はリクエストごとに新しい
 * プロセスが使われるため無害）。
 */

it('館別ページを訪問するとセッションとCookieに館が保持される（4.1.3-1）', function () {
    createCinema('kyoto', 'ムビ京都');

    $response = $this->get(route('front.cinema.show', ['slug' => 'kyoto']));

    $response->assertOk();
    expect(session(Cinema::SESSION_KEY))->toBe('kyoto');
    $response->assertCookie(Cinema::SESSION_KEY, 'kyoto');
});

it('セッション・Cookieとも未保持の場合は祇園ムビに解決される（4.1.3-2）', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee('value="'.route('front.cinema.show', ['slug' => 'gion']).'" selected', false);
});

it('セッションに館が保持されている場合はその館を使う', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->withSession([Cinema::SESSION_KEY => 'kyoto'])
        ->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee('value="'.route('front.cinema.show', ['slug' => 'kyoto']).'" selected', false);
});

it('Cookieのみ保持している場合はその館を使う', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->withCookie(Cinema::SESSION_KEY, 'kyoto')
        ->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee('value="'.route('front.cinema.show', ['slug' => 'kyoto']).'" selected', false);
});

it('セッションがCookieより優先される', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->withSession([Cinema::SESSION_KEY => 'gion'])
        ->withCookie(Cinema::SESSION_KEY, 'kyoto')
        ->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee('value="'.route('front.cinema.show', ['slug' => 'gion']).'" selected', false);
});

it('セッション・Cookieが実在しない館を指す場合は祇園ムビへ解決される', function () {
    createCinema('gion', '祇園ムビ');

    $this->withSession([Cinema::SESSION_KEY => 'nonexistent'])
        ->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee('value="'.route('front.cinema.show', ['slug' => 'gion']).'" selected', false);
});

it('祇園ムビが存在しない場合は任意の1館へ解決される', function () {
    createCinema('kyoto', 'ムビ京都');

    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee('value="'.route('front.cinema.show', ['slug' => 'kyoto']).'" selected', false);
});

it('館が1件も存在しない場合は404を返す', function () {
    $this->get(route('front.prices.index'))->assertNotFound();
});
