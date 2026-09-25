<?php

use App\Enums\PostStatus;

it('全館共通の記事と自館の記事を表示し、他館専用の記事は表示しない', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $other = createCinema('kyoto', 'ムビ京都');

    $shared = createPost(['title' => '全館共通のお知らせ', 'cinema_id' => null]);
    $own = createPost(['title' => '自館向けのお知らせ', 'cinema_id' => $cinema->id]);
    $otherOnly = createPost(['title' => '他館向けのお知らせ', 'cinema_id' => $other->id]);

    $this->get(route('front.news.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee($shared->title)
        ->assertSee($own->title)
        ->assertDontSee($otherOnly->title);
});

it('下書き・公開日時が未来・公開日時がNULLの記事を表示しない', function () {
    createCinema('gion', '祇園ムビ');

    $draft = createPost(['title' => '下書きのお知らせ', 'status' => PostStatus::Draft]);
    $future = createPost(['title' => '公開予定のお知らせ', 'published_at' => now()->addDay()]);
    $noPublishedAt = createPost(['title' => '公開日時未設定のお知らせ', 'published_at' => null]);

    $this->get(route('front.news.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertDontSee($draft->title)
        ->assertDontSee($future->title)
        ->assertDontSee($noPublishedAt->title);
});

it('公開日時の降順で並ぶ', function () {
    createCinema('gion', '祇園ムビ');

    $old = createPost(['title' => '古い記事', 'published_at' => now()->subDays(3)]);
    $new = createPost(['title' => '新しい記事', 'published_at' => now()->subDay()]);

    $content = $this->get(route('front.news.index', ['slug' => 'gion']))->assertOk()->getContent();

    expect(strpos($content, $new->title))->toBeLessThan(strpos($content, $old->title));
});

it('1ページあたり25件で、26件目以降は次ページに表示される', function () {
    createCinema('gion', '祇園ムビ');

    foreach (range(1, 26) as $i) {
        createPost(['title' => "テスト記事{$i}", 'published_at' => now()->subMinutes(30 - $i)]);
    }

    $this->get(route('front.news.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertViewHas('posts', fn ($posts) => $posts->total() === 26 && $posts->count() === 25);

    $this->get(route('front.news.index', ['slug' => 'gion', 'page' => 2]))
        ->assertOk()
        ->assertViewHas('posts', fn ($posts) => $posts->count() === 1);
});

it('カテゴリーで絞り込む', function () {
    createCinema('gion', '祇園ムビ');
    $notice = createPostCategory('notice', 'お知らせ');
    $campaign = createPostCategory('campaign', 'キャンペーン');

    $noticePost = createPost(['title' => 'お知らせカテゴリーの記事', 'category_id' => $notice->id]);
    $campaignPost = createPost(['title' => 'キャンペーンカテゴリーの記事', 'category_id' => $campaign->id]);

    $this->get(route('front.news.category', ['slug' => 'gion', 'category' => 'campaign']))
        ->assertOk()
        ->assertSee($campaignPost->title)
        ->assertDontSee($noticePost->title);
});

it('存在しないカテゴリーは404を返す', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.news.category', ['slug' => 'gion', 'category' => 'unknown']))
        ->assertNotFound();
});

it('idを伴わない news/detail は404を返す', function () {
    createCinema('gion', '祇園ムビ');

    $this->get('/cinemas/gion/news/detail')->assertNotFound();
});

it('記事が無いときの1ページ目は案内を表示し、範囲外のページは404を返す', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.news.index', ['slug' => 'gion']))
        ->assertOk()
        ->assertSee(__('front.news.empty'));

    $this->get(route('front.news.index', ['slug' => 'gion', 'page' => 2]))
        ->assertNotFound();
});

it('カテゴリー別の description はカテゴリー名を含み、正規URLは slug の小文字で組み立てる', function () {
    createCinema('gion', '祇園ムビ');
    $campaign = createPostCategory('campaign', 'キャンペーン');

    foreach (range(1, 26) as $_) {
        createPost(['category_id' => $campaign->id]);
    }

    $this->get('/cinemas/gion/news/CAMPAIGN?page=2')
        ->assertOk()
        ->assertSee('<meta name="description" content="'.e(__('front.news.category_description', ['cinema' => '祇園ムビ', 'address' => '京都府京都市', 'category' => 'キャンペーン'])), false)
        ->assertSee('<link rel="canonical" href="'.route('front.news.category', ['slug' => 'gion', 'category' => 'campaign']).'?page=2">', false);
});
