<?php

use App\Enums\PostStatus;

it('全館共通の記事を表示する', function () {
    createCinema('gion', '祇園ムビ');
    $post = createPost(['title' => '全館共通のお知らせ', 'cinema_id' => null]);

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $post->id]))
        ->assertOk()
        ->assertSee($post->title);
});

it('自館の記事を表示する', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $post = createPost(['title' => '自館向けのお知らせ', 'cinema_id' => $cinema->id]);

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $post->id]))
        ->assertOk()
        ->assertSee($post->title);
});

it('他館専用・下書き・公開日時が未来・公開日時がNULL・存在しないIDは404を返す', function () {
    createCinema('gion', '祇園ムビ');
    $other = createCinema('kyoto', 'ムビ京都');

    $otherOnly = createPost(['cinema_id' => $other->id]);
    $draft = createPost(['status' => PostStatus::Draft]);
    $future = createPost(['published_at' => now()->addDay()]);
    $unscheduled = createPost(['published_at' => null]);

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $otherOnly->id]))->assertNotFound();
    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $draft->id]))->assertNotFound();
    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $future->id]))->assertNotFound();
    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $unscheduled->id]))->assertNotFound();
    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => 999999]))->assertNotFound();
});

it('本文がMarkdownとしてレンダリングされる', function () {
    createCinema('gion', '祇園ムビ');
    $post = createPost(['body' => "## 見出し\n\n本文です。"]);

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $post->id]))
        ->assertOk()
        ->assertSee('<h3>見出し</h3>', false)
        ->assertSee('本文です。');
});

it('本文中のscriptタグがページに出ない', function () {
    createCinema('gion', '祇園ムビ');
    $post = createPost(['body' => "本文\n\n<script>alert(1)</script>"]);

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $post->id]))
        ->assertOk()
        ->assertDontSee('<script>', false);
});

it('titleが「記事タイトル｜お知らせ｜館名｜MOVI」の形式になる（19.2）', function () {
    createCinema('gion', '祇園ムビ');
    $post = createPost(['title' => 'サンプルのお知らせ']);

    $this->get(route('front.news.show', ['slug' => 'gion', 'id' => $post->id]))
        ->assertOk()
        ->assertSee('<title>サンプルのお知らせ｜お知らせ｜祇園ムビ｜MOVI</title>', false);
});
