<?php

/**
 * 施設案内（P-27、7.1.1 / 4.9.1）。館マスタの内容を表示する。
 */
it('館マスタの concept・facility_info・business_hours・phone を表示する', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $cinema->update([
        'concept' => '趣のある小規模館',
        'facility_info' => '売店あり',
        'business_hours' => '8:00〜23:55',
        'phone' => '123-4567-8900',
    ]);

    $this->get(route('front.establishment.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('趣のある小規模館')
        ->assertSee('売店あり')
        ->assertSee('8:00〜23:55')
        ->assertSee('123-4567-8900');
});

it('電話番号を tel: リンクにし、href は数字のみにする', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $cinema->update(['phone' => '123-4567-8900']);

    $this->get(route('front.establishment.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('href="tel:12345678900"', false);
});

it('facility_info のHTMLはエスケープして表示する', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $cinema->update(['facility_info' => "<script>alert(1)</script>\n売店あり"]);

    $html = $this->get(route('front.establishment.index', ['slug' => 'gion']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('titleが「施設案内｜{館名}｜MOVI」で、canonicalとパンくずのJSON-LDを含む', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.establishment.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('<title>施設案内｜祇園ムビ｜MOVI</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('front.establishment.index', ['slug' => 'gion']).'">', false)
        ->assertSee('"@type":"BreadcrumbList"', false);
});

it('アクセスページへのリンクを表示する', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.establishment.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('href="'.route('front.access.index', ['slug' => 'gion']).'"', false);
});

it('電話番号に数字が含まれない場合は tel: リンクにしない', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $cinema->update(['phone' => '窓口へお問い合わせください']);

    $this->get(route('front.establishment.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('窓口へお問い合わせください')
        ->assertDontSee('href="tel:', false);
});
