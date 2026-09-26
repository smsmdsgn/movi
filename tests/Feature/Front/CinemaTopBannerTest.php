<?php

use App\Enums\BannerPosition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * 館トップ（P-21）のバナー掲載（7.3-2・4・5・9）の検証。
 */
beforeEach(function () {
    Storage::fake('public');
});

it('掲載期間内の全館共通・自館のバナーを表示し、他館専用・期間外のバナーは表示しない', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');

    createBanner(['position' => BannerPosition::FooterLink, 'cinema_id' => $gion->id, 'alt' => '自館バナー']);
    createBanner(['position' => BannerPosition::FooterLink, 'cinema_id' => null, 'alt' => '全館共通バナー']);
    createBanner(['position' => BannerPosition::FooterLink, 'cinema_id' => $kyoto->id, 'alt' => '他館バナー']);
    createBanner(['position' => BannerPosition::FooterLink, 'cinema_id' => $gion->id, 'alt' => '期間終了バナー', 'ends_at' => now()->subDay()]);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('自館バナー')
        ->assertSee('全館共通バナー')
        ->assertDontSee('他館バナー')
        ->assertDontSee('期間終了バナー');
});

it('メインバナーは表示順の先頭1枚のみ表示する', function () {
    createCinema('gion', '祇園ムビ');

    createBanner(['position' => BannerPosition::Main, 'sort_order' => 2, 'alt' => 'メイン2番目']);
    createBanner(['position' => BannerPosition::Main, 'sort_order' => 1, 'alt' => 'メイン1番目']);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('メイン1番目')
        ->assertDontSee('メイン2番目');
});

it('小バナーは表示順の先頭2枚までを表示する', function () {
    createCinema('gion', '祇園ムビ');

    createBanner(['position' => BannerPosition::Sub, 'sort_order' => 1, 'alt' => 'Sub1']);
    createBanner(['position' => BannerPosition::Sub, 'sort_order' => 2, 'alt' => 'Sub2']);
    createBanner(['position' => BannerPosition::Sub, 'sort_order' => 3, 'alt' => 'Sub3']);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('Sub1')
        ->assertSee('Sub2')
        ->assertDontSee('Sub3');
});

it('リンクURLは href と rel="noopener noreferrer" で出力し、javascript: スキームはリンクにしない（17.5.2-4）', function () {
    createCinema('gion', '祇園ムビ');

    createBanner(['position' => BannerPosition::FooterLink, 'link_url' => 'https://example.com/promo', 'alt' => 'リンクバナー']);
    createBanner(['position' => BannerPosition::FooterLink, 'link_url' => 'javascript:alert(1)', 'alt' => '危険バナー']);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('href="https://example.com/promo" rel="noopener noreferrer"', false)
        ->assertDontSee('javascript:alert(1)', false);
});

it('画像が実在しない場合はプレースホルダーを表示する', function () {
    createCinema('gion', '祇園ムビ');

    createBanner(['position' => BannerPosition::Main, 'alt' => 'プレースホルダーバナー']);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('aria-label="プレースホルダーバナー"', false);
});

it('画像が実在する場合は<img>を表示し、メインバナーのみ loading="eager" とする', function () {
    createCinema('gion', '祇園ムビ');

    $mainPath = UploadedFile::fake()->image('main.jpg', 970, 250)->store('banners', 'public');
    $subPath = UploadedFile::fake()->image('sub.jpg', 620, 130)->store('banners', 'public');

    createBanner(['position' => BannerPosition::Main, 'image_path' => $mainPath, 'alt' => 'メイン画像バナー']);
    createBanner(['position' => BannerPosition::Sub, 'image_path' => $subPath, 'alt' => 'サブ画像バナー']);

    $html = $this->get(route('front.cinema.show', ['slug' => 'gion']))->assertOk()->getContent();

    expect($html)->toContain('src="'.Storage::disk('public')->url($mainPath).'"')
        ->toContain('loading="eager"')
        ->toContain('src="'.Storage::disk('public')->url($subPath).'"')
        ->toContain('loading="lazy"');
});

it('カルーセルは1枚のとき前へ／次へボタンを出さず、2枚以上のとき出す', function () {
    createCinema('gion', '祇園ムビ');

    createBanner(['position' => BannerPosition::Carousel, 'alt' => 'カルーセル1']);

    $html = $this->get(route('front.cinema.show', ['slug' => 'gion']))->assertOk()->getContent();
    expect($html)->not->toContain(__('front.cinema_top.carousel.prev'));

    createBanner(['position' => BannerPosition::Carousel, 'alt' => 'カルーセル2']);

    $html = $this->get(route('front.cinema.show', ['slug' => 'gion']))->assertOk()->getContent();
    expect($html)->toContain(__('front.cinema_top.carousel.prev'))
        ->toContain(__('front.cinema_top.carousel.next'));
});

it('カルーセルのバナーが0枚の場合は区画を表示しない', function () {
    createCinema('gion', '祇園ムビ');

    $html = $this->get(route('front.cinema.show', ['slug' => 'gion']))->assertOk()->getContent();

    expect($html)->not->toContain('aria-roledescription="carousel"');
});
