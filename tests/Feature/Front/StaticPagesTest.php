<?php

use App\Models\Cinema;
use App\Models\FreeTicket;
use App\Models\Screening;
use App\Services\SeatLockService;

/*
 * 館非依存の静的ページ（P-08〜P-13, P-16〜P-20、4.9.1 / 7.1.1 / 19.2。工程7-f）。
 * 各画面が200を返し、title と h1 を正しく出し、旧工程のプレースホルダが出していた
 * 画面ID（P-08 等）をもう表示しないことを確認する。
 */
it('title・h1を表示し、画面IDは表示しない', function (string $routeName, string $title, string $heading, string $screenId) {
    createCinema('gion', '祇園ムビ');

    $this->get(route($routeName))
        ->assertOk()
        ->assertSee("<title>{$title}</title>", false)
        ->assertSee($heading)
        ->assertDontSee($screenId);
})->with([
    'P-08 料金表・割引サービス' => ['front.prices.index', '料金表・割引サービス｜MOVI', '料金表・割引サービス', 'P-08'],
    'P-09 フード・ドリンクメニュー' => ['front.food.index', 'フード・ドリンクメニュー｜MOVI', 'フード・ドリンクメニュー', 'P-09'],
    'P-10 前売り券情報' => ['front.presale.index', '前売り券情報｜MOVI', '前売り券情報', 'P-10'],
    'P-11 よくある質問' => ['front.faq.index', 'よくある質問｜MOVI', 'よくある質問', 'P-11'],
    'P-12 採用情報' => ['front.recruit.index', '採用情報｜MOVI', '採用情報', 'P-12'],
    'P-13 会社情報' => ['front.company.index', '会社情報｜MOVI', '会社情報', 'P-13'],
    'P-16 利用規約' => ['front.terms.index', '利用規約｜MOVI', '利用規約', 'P-16'],
    'P-17 プライバシーポリシー' => ['front.privacy.index', 'プライバシーポリシー｜MOVI', 'プライバシーポリシー', 'P-17'],
    'P-18 Cookieポリシー' => ['front.cookie-policy.index', 'Cookieポリシー｜MOVI', 'Cookieポリシー', 'P-18'],
    'P-19 特定商取引法に基づく表記' => ['front.legal.index', '特定商取引法に基づく表記｜MOVI', '特定商取引法に基づく表記', 'P-19'],
    'P-20 サイトマップ' => ['front.sitemap.index', 'サイトマップ｜MOVI', 'サイトマップ', 'P-20'],
]);

it('パンくずに館を挟まず、ホームと見出しのみを表示する（19.3-9）', function () {
    createCinema('gion', '祇園ムビ');

    $html = $this->get(route('front.prices.index'))->assertOk()->getContent();

    /* ヘッダーの劇場切替にも館名が出るため、パンくずの nav 要素に絞って判定する。 */
    preg_match('#<nav aria-label="'.preg_quote(__('front.breadcrumb.label'), '#').'">(.*?)</nav>#s', $html, $matches);

    expect($matches)->toHaveKey(1)
        ->and($matches[1])->toContain(__('front.breadcrumb.home'))
        ->and($matches[1])->toContain('料金表・割引サービス')
        ->and($matches[1])->not->toContain('祇園ムビ');
});

it('ダミー本文のページは冒頭に架空のデモサイトである旨の注記を表示する（4.9.1）', function (string $routeName) {
    createCinema('gion', '祇園ムビ');

    $this->get(route($routeName))
        ->assertOk()
        ->assertSee(__('front.pages.demo_notice'));
})->with([
    'P-10 前売り券情報' => ['front.presale.index'],
    'P-12 採用情報' => ['front.recruit.index'],
    'P-13 会社情報' => ['front.company.index'],
    'P-16 利用規約' => ['front.terms.index'],
    'P-17 プライバシーポリシー' => ['front.privacy.index'],
    'P-18 Cookieポリシー' => ['front.cookie-policy.index'],
    'P-19 特定商取引法に基づく表記' => ['front.legal.index'],
]);

it('よくある質問の仕様値（座席上限・キャンセル期限・スタンプ数）は定数から表示する（4.3.4・4.4・4.5.1）', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.faq.index'))
        ->assertOk()
        ->assertSee('お座席は'.SeatLockService::MAX_SEATS_PER_HOLDER.'席までです。')
        ->assertSee('上映開始の'.Screening::CANCEL_DEADLINE_MINUTES.'分前まで')
        ->assertSee(FreeTicket::STAMPS_PER_TICKET.'個貯まりますと')
        ->assertSee(__('front.mypage.stamp.free_ticket_note'))
        ->assertDontSee('は:max席')
        ->assertDontSee('の:minutes分')
        ->assertDontSee(':stamps個');
});

it('特定商取引法に基づく表記のキャンセル期限は定数から表示する（4.4）', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.legal.index'))
        ->assertOk()
        ->assertSee('上映開始の'.Screening::CANCEL_DEADLINE_MINUTES.'分前まで')
        ->assertDontSee('の:minutes分');
});

it('Cookieポリシーは実装が発行するCookieを載せ、未実装の同意ダイアログを既存のものとして案内しない（4.9.5）', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.cookie-policy.index'))
        ->assertOk()
        ->assertSee(Cinema::SESSION_KEY)
        ->assertSee('XSRF-TOKEN')
        ->assertSee('remember_web_')
        ->assertSee('__stripe_mid')
        ->assertSee('導入を予定しております');
});
