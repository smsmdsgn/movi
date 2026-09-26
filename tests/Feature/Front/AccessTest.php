<?php

/**
 * アクセス（P-28、7.1.1 / 4.9.1）。所在地・アクセス補足・地図を表示する。
 */
it('所在地とアクセス補足を表示する', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $cinema->update([
        'address' => '京都府京都市東山区祇園町北側',
        'access_note' => '京阪 祇園四条駅から徒歩3分',
    ]);

    $this->get(route('front.access.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('京都府京都市東山区祇園町北側')
        ->assertSee('京阪 祇園四条駅から徒歩3分');
});

it('map_embed_url がGoogleの埋め込みURLならiframeを表示する', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $cinema->update(['map_embed_url' => 'https://www.google.com/maps?q=test&output=embed']);

    $this->get(route('front.access.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('<iframe', false)
        ->assertSee('src="https://www.google.com/maps?q=test&amp;output=embed"', false)
        ->assertSee('loading="lazy"', false);
});

it('map_embed_url が許可外のホストの場合はiframeを表示しない', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $cinema->update(['map_embed_url' => 'https://evil.example/']);

    $this->get(route('front.access.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertDontSee('<iframe', false);
});

it('titleが「アクセス｜{館名}｜MOVI」で、canonicalとパンくずのJSON-LDを含む', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.access.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('<title>アクセス｜祇園ムビ｜MOVI</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('front.access.index', ['slug' => 'gion']).'">', false)
        ->assertSee('"@type":"BreadcrumbList"', false);
});

it('施設案内ページへのリンクを表示する', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.access.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('href="'.route('front.establishment.index', ['slug' => 'gion']).'"', false);
});

it('access_note のHTMLはエスケープして表示する', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $cinema->update(['access_note' => "<script>alert(1)</script>\n徒歩5分"]);

    $html = $this->get(route('front.access.index', ['slug' => 'gion']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});
