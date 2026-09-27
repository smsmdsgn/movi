<?php

it('館非依存ページ（7.1.1 P-14・P-15）のルートが画面IDを返す', function (string $routeName, string $screenId, array $parameters) {
    createCinema('gion', '祇園ムビ');

    $this->get(route($routeName, $parameters))
        ->assertOk()
        ->assertSee($screenId);
})->with([
    'P-14 お問い合わせ' => ['front.contact.index', 'P-14', []],
    'P-15 お問い合わせ送信完了' => ['front.contact.complete', 'P-15', []],
]);

/*
 * P-08〜P-13・P-16〜P-20 は工程7-fで実装したため、本ファイルの対象から外した。
 * 表示内容は tests/Feature/Front/StaticPagesTest.php・PricePageTest.php・
 * TermsPageTest.php・SitemapPageTest.php・FoodPageTest.php で検証する（4.9.1）。
 *
 * P-38（予約完了）は工程5-mで実装したため、本ファイルの対象から外した。所有者の判定を
 * 含むため tests/Feature/Front/ReservationCompleteTest.php で検証する（4.3.16）。
 *
 * P-07（予約照会）は工程5-nで実装したため、同じく対象から外した。照合とレート制限を
 * 含むため tests/Feature/Front/LookupTest.php で検証する（4.3.17）。
 *
 * P-05（マイページ）は工程6-aで実装したため、画面IDを返す対象から外した（未ログイン時に
 * ログイン画面へ遷移することは引き続きここで検証する）。表示項目は
 * tests/Feature/Front/MyPageTest.php で検証する（4.5.3）。
 *
 * P-06（予約詳細）は工程6-bで実装したため、同じく画面IDを返す対象から外した。所有者の
 * 判定とキャンセルを含むため tests/Feature/Front/MyPageReservationDetailTest.php で
 * 検証する（4.5.4）。
 */

it('館非依存ページ（7.1.1 P-05, P-06）は会員専用のため未ログインでは401ではなくログイン画面へ遷移する', function (string $routeName, array $parameters) {
    createCinema('gion', '祇園ムビ');

    $this->get(route($routeName, $parameters))
        ->assertRedirect(route('login'));
})->with([
    'P-05 マイページ' => ['front.mypage.index', []],
    'P-06 マイページ予約詳細' => ['front.mypage.reservation.show', ['id' => 1]],
]);
