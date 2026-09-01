<?php

use App\Http\Controllers\Front\PlaceholderController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
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
