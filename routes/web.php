<?php

use App\Http\Controllers\Front\AgreementController;
use App\Http\Controllers\Front\ChainTopController;
use App\Http\Controllers\Front\CinemaTopController;
use App\Http\Controllers\Front\IdentifyController;
use App\Http\Controllers\Front\MovieController;
use App\Http\Controllers\Front\PagePlaceholderController;
use App\Http\Controllers\Front\PlaceholderController;
use App\Http\Controllers\Front\ScheduleController;
use App\Http\Controllers\Front\SeatSelectionController;
use App\Http\Middleware\SkipCinemaScope;
use App\Models\Cinema;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| 顧客向けページ
|--------------------------------------------------------------------------
|
| 顧客向けのルートには SkipCinemaScope を付け、管理者のセッションが同一ブラウザに
| 残っていても CinemaScope（13.4.1）で絞り込まれないようにする（4.8.6追記表）。
| 顧客側の館の絞り込みは ResolveCinema が解決した館で明示的に行う。
|
*/
Route::middleware(SkipCinemaScope::class)->group(function (): void {

    /*
    |--------------------------------------------------------------------------
    | 館非依存ページ（7.1.1 P-01, P-05〜P-20）
    |--------------------------------------------------------------------------
    |
    | P-01（チェーントップ）は実装済み。それ以外は工程2ではルート骨格のみを
    | 実装するため、PagePlaceholderController が画面IDと現在の館（ヘッダー表示用、
    | CurrentCinemaService で解決）のみを返す。各画面の実装（該当フェーズ、11.1）で
    | 画面ごとの内容へ差し替える。
    |
    | P-02（会員登録）・P-03（ログイン）・P-04（パスワード再設定）は対象外。
    | P-03・P-04 は Fortify が既に実ルートとして提供しており、いずれも認証画面
    | として Flux UI の対象（13.5-3）のため、本レイアウトの適用対象外
    |（design.md 4.1.3 追記表）。
    |
    | P-05・P-06（マイページ）は会員専用画面（7.14）のため `auth` を付与する。
    |
    | 予約フロー（P-31〜P-38）のうち、P-31（座席選択）は工程5-bで、P-32（同意画面）は
    | 工程5-cで、P-33（会員／非会員の選択）は工程5-dで実装済み。P-34（お客様情報の入力）と
    | P-35（券種選択）は P-33 の2つの遷移先として必要なため、ルート骨格のみを先行して置く
    |（P-36以降も同じ扱いで、それぞれの実装時に差し替える）。
    |
    */
    Route::get('/', ChainTopController::class)->name('front.home');

    Route::name('front.')->group(function () {
        Route::middleware('auth')->group(function () {
            Route::get('mypage', PagePlaceholderController::class)->defaults('screenId', 'P-05')->name('mypage.index');
            Route::get('mypage/reservations/{id}', PagePlaceholderController::class)->defaults('screenId', 'P-06')->name('mypage.reservation.show')->whereNumber('id');
        });
        Route::get('lookup', PagePlaceholderController::class)->defaults('screenId', 'P-07')->name('lookup.index');
        Route::get('prices', PagePlaceholderController::class)->defaults('screenId', 'P-08')->name('prices.index');
        Route::get('food', PagePlaceholderController::class)->defaults('screenId', 'P-09')->name('food.index');
        Route::get('presale', PagePlaceholderController::class)->defaults('screenId', 'P-10')->name('presale.index');
        Route::get('faq', PagePlaceholderController::class)->defaults('screenId', 'P-11')->name('faq.index');
        Route::get('recruit', PagePlaceholderController::class)->defaults('screenId', 'P-12')->name('recruit.index');
        Route::get('company', PagePlaceholderController::class)->defaults('screenId', 'P-13')->name('company.index');
        Route::get('contact', PagePlaceholderController::class)->defaults('screenId', 'P-14')->name('contact.index');
        Route::get('contact/complete', PagePlaceholderController::class)->defaults('screenId', 'P-15')->name('contact.complete');
        Route::get('terms', PagePlaceholderController::class)->defaults('screenId', 'P-16')->name('terms.index');
        Route::get('privacy', PagePlaceholderController::class)->defaults('screenId', 'P-17')->name('privacy.index');
        Route::get('cookie-policy', PagePlaceholderController::class)->defaults('screenId', 'P-18')->name('cookie-policy.index');
        Route::get('legal', PagePlaceholderController::class)->defaults('screenId', 'P-19')->name('legal.index');
        Route::get('sitemap', PagePlaceholderController::class)->defaults('screenId', 'P-20')->name('sitemap.index');
        Route::get('screenings/{id}/seats', SeatSelectionController::class)->name('reservation.seats')->whereNumber('id');
        Route::get('screenings/{id}/agreement', AgreementController::class)->name('reservation.agreement')->whereNumber('id');
        Route::get('screenings/{id}/identify', IdentifyController::class)->name('reservation.identify')->whereNumber('id');
        Route::get('screenings/{id}/customer', PagePlaceholderController::class)->defaults('screenId', 'P-34')->name('reservation.customer')->whereNumber('id');
        Route::get('screenings/{id}/tickets', PagePlaceholderController::class)->defaults('screenId', 'P-35')->name('reservation.tickets')->whereNumber('id');
    });

    /*
    |--------------------------------------------------------------------------
    | 館別ページ（7.1.1 P-21〜P-28）
    |--------------------------------------------------------------------------
    |
    | {slug} は ResolveCinema が館へ解決してコンテナへバインドする（13.4.1）。
    | P-21〜P-23 は工程4で実装済み。P-24〜P-28 は該当工程（11.1）で実装するため、
    | 現時点は PlaceholderController が画面IDと館名のみを返す。画面IDは defaults() で
    | コントローラへ渡す。
    |
    | ルートのアクションにクロージャを使用しないこと。route:cache（15.3.2）は
    | クロージャを直列化する際に外側の候補を選び、エラーを出さないまま
    | 本番でのみ壊れたルートを生成する。
    |
    */
    Route::prefix('cinemas/{slug}')
        ->where(['slug' => Cinema::SLUG_REGEX])
        ->middleware('cinema')
        ->name('front.')
        ->group(function () {
            Route::get('/', CinemaTopController::class)->name('cinema.show');
            Route::get('schedule', ScheduleController::class)->name('schedule.index');
            Route::get('movies/{id}', MovieController::class)->name('movie.show')->whereNumber('id');
            Route::get('news', PlaceholderController::class)->defaults('screenId', 'P-24')->name('news.index');
            Route::get('news/detail/{id}', PlaceholderController::class)->defaults('screenId', 'P-26')->name('news.show')->whereNumber('id');
            Route::get('news/{category}', PlaceholderController::class)->defaults('screenId', 'P-25')->name('news.category');
            Route::get('establishment', PlaceholderController::class)->defaults('screenId', 'P-27')->name('establishment.index');
            Route::get('access', PlaceholderController::class)->defaults('screenId', 'P-28')->name('access.index');
        });

});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
