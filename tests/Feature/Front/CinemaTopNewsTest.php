<?php

use App\Enums\PostStatus;
use App\Models\PostCategory;
use Carbon\CarbonImmutable;

/**
 * 館トップ（P-21）のお知らせ掲載（7.3-6・7）の検証。
 */
it('重要なお知らせは該当記事がある場合のみ区画を表示する', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertDontSee('重要なお知らせ');

    $important = createPostCategory('important', '重要なお知らせ');
    /* 2件以上にする。ビューで記事からカテゴリーを遅延読み込みすると、2件以上のコレクションでは
       Model::preventLazyLoading() に触れて500になる（工程7-dのレビューで検出）。 */
    createPost(['category_id' => $important->id, 'title' => '休館のお知らせ']);
    createPost(['category_id' => $important->id, 'title' => '上映中止のお知らせ']);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('重要なお知らせ')
        ->assertSee('休館のお知らせ')
        ->assertSee('上映中止のお知らせ');
});

it('お知らせ／キャンペーンは各カテゴリーの最新5件のみを新しい順で表示する', function () {
    createCinema('gion', '祇園ムビ');
    $notice = createPostCategory('notice', 'お知らせ');

    $today = CarbonImmutable::parse('2026-05-10 00:00:00');
    foreach (range(1, 6) as $i) {
        createPost([
            'category_id' => $notice->id,
            'title' => "お知らせ{$i}",
            'published_at' => $today->subDays(6 - $i),
        ]);
    }

    $html = $this->get(route('front.cinema.show', ['slug' => 'gion']))->assertOk()->getContent();

    $positions = array_map(fn (int $i) => strpos($html, "お知らせ{$i}"), [6, 5, 4, 3, 2]);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and($html)->not->toContain('お知らせ1');
});

it('重要なお知らせのカテゴリーがあっても表示できる記事が無い場合は区画を表示しない', function () {
    createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $important = createPostCategory('important', '重要なお知らせ');

    createPost(['category_id' => $important->id, 'title' => '他館の休館', 'cinema_id' => $kyoto->id]);
    createPost(['category_id' => $important->id, 'title' => '下書きの休館', 'status' => PostStatus::Draft]);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertDontSee('重要なお知らせ')
        ->assertDontSee('href="'.route('front.news.category', ['slug' => 'gion', 'category' => 'important']).'"', false);
});

it('非公開・他館専用の記事はお知らせ／キャンペーン区画に表示しない', function () {
    createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $campaign = createPostCategory('campaign', 'キャンペーン');

    createPost(['category_id' => $campaign->id, 'title' => '下書きキャンペーン', 'status' => PostStatus::Draft, 'published_at' => null]);
    createPost(['category_id' => $campaign->id, 'title' => '他館キャンペーン', 'cinema_id' => $kyoto->id]);
    createPost(['category_id' => $campaign->id, 'title' => '公開中キャンペーン']);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('公開中キャンペーン')
        ->assertDontSee('下書きキャンペーン')
        ->assertDontSee('他館キャンペーン');
});

it('重要なお知らせ・お知らせ・キャンペーンの各区画にアーカイブへのリンクを表示する', function () {
    createCinema('gion', '祇園ムビ');
    createPost(['category_id' => createPostCategory('important', '重要なお知らせ')->id]);
    createPost(['category_id' => createPostCategory('notice', 'お知らせ')->id]);
    createPost(['category_id' => createPostCategory('campaign', 'キャンペーン')->id]);

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('href="'.route('front.news.category', ['slug' => 'gion', 'category' => 'important']).'"', false)
        ->assertSee('href="'.route('front.news.category', ['slug' => 'gion', 'category' => 'notice']).'"', false)
        ->assertSee('href="'.route('front.news.category', ['slug' => 'gion', 'category' => 'campaign']).'"', false);
});

it('記事が0件のカテゴリーは案内文とアーカイブへのリンクを表示する', function () {
    createCinema('gion', '祇園ムビ');
    createPostCategory('notice', 'お知らせ');

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee(__('front.cinema_top.news.empty', ['category' => 'お知らせ']))
        ->assertSee('href="'.route('front.news.category', ['slug' => 'gion', 'category' => 'notice']).'"', false);
});

it('カテゴリーの行が無い場合はその区画を表示せず、ページは表示する', function () {
    createCinema('gion', '祇園ムビ');
    createPost(['category_id' => createPostCategory('campaign', 'キャンペーン')->id, 'title' => '公開中キャンペーン']);
    /* createPost() は既定値の組み立てで notice のカテゴリーを作るため、ここで消して「行が無い」状態にする。 */
    PostCategory::query()->where('slug', 'notice')->delete();

    $this->get(route('front.cinema.show', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee('公開中キャンペーン')
        ->assertDontSee('href="'.route('front.news.category', ['slug' => 'gion', 'category' => 'notice']).'"', false)
        ->assertDontSee('href="'.route('front.news.category', ['slug' => 'gion', 'category' => 'important']).'"', false);
});
