<?php

use App\Http\Controllers\Front\ChainTopController;
use App\Http\Controllers\Front\PagePlaceholderController;
use App\Http\Controllers\Front\PlaceholderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

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
});

/*
|--------------------------------------------------------------------------
| 館別ページ（7.1.1 P-21〜P-28）
|--------------------------------------------------------------------------
|
| {slug} は ResolveCinema が館へ解決してコンテナへバインドする（13.4.1）。
| 各ページの中身は工程4以降で実装するため、現時点は PlaceholderController が
| 画面IDと館名のみを返す。画面IDは defaults() でコントローラへ渡す。
|
| ルートのアクションにクロージャを使用しないこと。route:cache（15.3.2）は
| クロージャを直列化する際に外側の候補を選び、エラーを出さないまま
| 本番でのみ壊れたルートを生成する。
|
*/
Route::prefix('cinemas/{slug}')
    ->where(['slug' => '[a-z]+(?:-[a-z]+)*'])
    ->middleware('cinema')
    ->name('front.')
    ->group(function () {
        Route::get('/', PlaceholderController::class)->defaults('screenId', 'P-21')->name('cinema.show');
        Route::get('schedule', PlaceholderController::class)->defaults('screenId', 'P-22')->name('schedule.index');
        Route::get('movies/{id}', PlaceholderController::class)->defaults('screenId', 'P-23')->name('movie.show')->whereNumber('id');
        Route::get('news', PlaceholderController::class)->defaults('screenId', 'P-24')->name('news.index');
        Route::get('news/detail/{id}', PlaceholderController::class)->defaults('screenId', 'P-26')->name('news.show')->whereNumber('id');
        Route::get('news/{category}', PlaceholderController::class)->defaults('screenId', 'P-25')->name('news.category');
        Route::get('establishment', PlaceholderController::class)->defaults('screenId', 'P-27')->name('establishment.index');
        Route::get('access', PlaceholderController::class)->defaults('screenId', 'P-28')->name('access.index');
    });

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
