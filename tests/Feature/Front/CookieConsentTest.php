<?php

use App\Enums\CookieConsent;
use App\Livewire\Front\CookieConsent\Dialog;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * Cookie同意ダイアログ（4.9.3 / 4.9.7。工程9-a）。顧客向け共通レイアウトの末尾に置く
 * Livewire コンポーネント。未選択の利用者にのみ表示し、選択（同意する／拒否する）は
 * Cookie（cookie_consent、1年）へ保存する。保存後は選択済みとしてコンポーネントごと
 * 出力されなくなること、計測タグ（@stack('tracking')）を同意した利用者にのみ出す
 * 分岐（4.9.7「計測タグの分岐」）も検証する。
 */

it('未選択の利用者に顧客向けページでダイアログを表示する（4.9.3 / 4.9.7）', function (?string $cookieValue) {
    createCinema('gion', '祇園ムビ');

    $request = $cookieValue === null ? $this : $this->withCookie(CookieConsent::COOKIE_NAME, $cookieValue);

    $request->get(route('front.faq.index'))
        ->assertOk()
        ->assertSeeLivewire(Dialog::class)
        ->assertSee(__('front.cookie_consent.heading'));
})->with([
    'Cookie なし' => [null],
    '未知の値' => ['unknown'],
]);

it('ダイアログは「同意する」「拒否する」のボタンとCookieポリシーへのリンクを持つ（4.9.3-2）', function () {
    /*
     * ページ全体ではフッターのリンクや本文の「拒否する」の語でも成立してしまうため、
     * コンポーネント単体の描画で確かめる。
     */
    Livewire::test(Dialog::class)
        ->assertSeeHtml('wire:click="accept"')
        ->assertSeeHtml('wire:click="reject"')
        ->assertSee(__('front.cookie_consent.accept'))
        ->assertSee(__('front.cookie_consent.reject'))
        ->assertSeeHtml('href="'.route('front.cookie-policy.index').'"');
});

it('選択済みの利用者にはダイアログをコンポーネントごと出力しない（4.9.7「構成」）', function (CookieConsent $consent) {
    createCinema('gion', '祇園ムビ');

    $this->withCookie(CookieConsent::COOKIE_NAME, $consent->value)
        ->get(route('front.faq.index'))
        ->assertOk()
        ->assertDontSeeLivewire(Dialog::class);
})->with([
    'accepted' => [CookieConsent::Accepted],
    'rejected' => [CookieConsent::Rejected],
]);

it('accept・rejectを呼ぶと選択結果を1年間有効なCookieとして保存し、ダイアログの中身を消す（4.9.3 / 4.9.7「保存するCookie」）', function (string $action, CookieConsent $expected) {
    $this->freezeTime();

    Livewire::test(Dialog::class)
        ->assertSee(__('front.cookie_consent.heading'))
        ->call($action)
        ->assertDontSee(__('front.cookie_consent.heading'));

    $cookie = Cookie::queued(CookieConsent::COOKIE_NAME);

    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe($expected->value)
        ->and($cookie->getExpiresTime())->toBe(now()->addMinutes(CookieConsent::LIFETIME_MINUTES)->getTimestamp());
})->with([
    '同意する' => ['accept', CookieConsent::Accepted],
    '拒否する' => ['reject', CookieConsent::Rejected],
]);

it('計測タグは同意した利用者にのみ出力する（4.9.3 / 4.9.7「計測タグの分岐」）', function (?string $cookieValue, bool $expectsTag) {
    createCinema('gion', '祇園ムビ');

    /*
     * `@stack('tracking')` を出す本番ページが現時点で無い（4.9.7「現時点で積むタグは無い」）
     * ため、分岐だけを検証する一時ルートをテスト内に用意する。`routes/` に置かないため
     * 「クロージャルートを使わない」（tests/Feature/Front/ResolveCinemaTest.php）の対象外。
     */
    Route::middleware('web')->get('/_test/tracking-probe', fn () => Blade::render(
        '<x-front.layout>@push(\'tracking\')<meta name="tracking-probe">@endpush</x-front.layout>'
    ));

    $request = $cookieValue === null ? $this : $this->withCookie(CookieConsent::COOKIE_NAME, $cookieValue);
    $response = $request->get('/_test/tracking-probe')->assertOk();

    if ($expectsTag) {
        $response->assertSee('name="tracking-probe"', false);
    } else {
        $response->assertDontSee('name="tracking-probe"', false);
    }
})->with([
    'Cookie なし' => [null, false],
    '未知の値' => ['unknown', false],
    'accepted' => [CookieConsent::Accepted->value, true],
    'rejected' => [CookieConsent::Rejected->value, false],
]);
