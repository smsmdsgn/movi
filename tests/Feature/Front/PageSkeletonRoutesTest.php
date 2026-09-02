<?php

use App\Models\User;

it('館非依存ページ（7.1.1 P-07〜P-20）のルートが画面IDを返す', function (string $routeName, string $screenId, array $parameters) {
    createCinema('gion', '祇園ムビ');

    $this->get(route($routeName, $parameters))
        ->assertOk()
        ->assertSee($screenId);
})->with([
    'P-07 予約照会' => ['front.lookup.index', 'P-07', []],
    'P-08 料金表・割引サービス' => ['front.prices.index', 'P-08', []],
    'P-09 フード・ドリンクメニュー' => ['front.food.index', 'P-09', []],
    'P-10 前売り券情報' => ['front.presale.index', 'P-10', []],
    'P-11 よくある質問' => ['front.faq.index', 'P-11', []],
    'P-12 採用情報' => ['front.recruit.index', 'P-12', []],
    'P-13 会社情報' => ['front.company.index', 'P-13', []],
    'P-14 お問い合わせ' => ['front.contact.index', 'P-14', []],
    'P-15 お問い合わせ送信完了' => ['front.contact.complete', 'P-15', []],
    'P-16 利用規約' => ['front.terms.index', 'P-16', []],
    'P-17 プライバシーポリシー' => ['front.privacy.index', 'P-17', []],
    'P-18 Cookieポリシー' => ['front.cookie-policy.index', 'P-18', []],
    'P-19 特定商取引法に基づく表記' => ['front.legal.index', 'P-19', []],
    'P-20 サイトマップ' => ['front.sitemap.index', 'P-20', []],
]);

it('館非依存ページ（7.1.1 P-05, P-06）は会員専用のため未ログインでは401ではなくログイン画面へ遷移する', function (string $routeName, array $parameters) {
    createCinema('gion', '祇園ムビ');

    $this->get(route($routeName, $parameters))
        ->assertRedirect(route('login'));
})->with([
    'P-05 マイページ' => ['front.mypage.index', []],
    'P-06 マイページ予約詳細' => ['front.mypage.reservation.show', ['id' => 1]],
]);

it('館非依存ページ（7.1.1 P-05, P-06）はログイン時に画面IDを返す', function (string $routeName, string $screenId, array $parameters) {
    createCinema('gion', '祇園ムビ');

    $this->actingAs(User::factory()->create())
        ->get(route($routeName, $parameters))
        ->assertOk()
        ->assertSee($screenId);
})->with([
    'P-05 マイページ' => ['front.mypage.index', 'P-05', []],
    'P-06 マイページ予約詳細' => ['front.mypage.reservation.show', 'P-06', ['id' => 1]],
]);
