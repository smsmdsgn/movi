<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\PagePlaceholderController;
use App\Http\Middleware\AuthorizeAdminScreen;
use App\Livewire\Admin\Auth\Login;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 管理画面（4.8.5 A-01〜A-16）
|--------------------------------------------------------------------------
|
| A-01（ログイン）のみ実装。A-02（ダッシュボード）は最低限の実装、
| A-03〜A-16 は工程3-aではルート骨格のみで、PagePlaceholderController が
| 画面IDのみを返す（12章 残課題）。各画面の実装（該当フェーズ、11.1）で
| 画面ごとの内容へ差し替える。
|
| 各画面への到達可否（4.8.2 / 4.8.5 / 17.1.3）は AuthorizeAdminScreen
| ミドルウェアが `view-admin-screen` Gate（AppServiceProvider）で判定する。
|
*/
Route::prefix('admin')->group(function (): void {
    Route::get('login', Login::class)->middleware('guest:admin')->name('admin.login');
    Route::post('logout', LogoutController::class)->middleware('auth:admin')->name('admin.logout');

    Route::middleware(['auth:admin', AuthorizeAdminScreen::class])->name('admin.')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('cinemas', PagePlaceholderController::class)->defaults('screenId', 'A-03')->name('cinema.index');
        Route::get('theaters', PagePlaceholderController::class)->defaults('screenId', 'A-04')->name('theater.index');
        Route::get('movies', PagePlaceholderController::class)->defaults('screenId', 'A-05')->name('movie.index');
        Route::get('formats', PagePlaceholderController::class)->defaults('screenId', 'A-06')->name('format.index');
        Route::get('ticket-types', PagePlaceholderController::class)->defaults('screenId', 'A-07')->name('ticket-type.index');
        Route::get('bookings', PagePlaceholderController::class)->defaults('screenId', 'A-08')->name('booking.index');
        Route::get('screenings', PagePlaceholderController::class)->defaults('screenId', 'A-09')->name('screening.index');
        Route::get('reservations', PagePlaceholderController::class)->defaults('screenId', 'A-10')->name('reservation.index');
        Route::get('reservations/search', PagePlaceholderController::class)->defaults('screenId', 'A-11')->name('reservation.search');
        Route::get('posts', PagePlaceholderController::class)->defaults('screenId', 'A-12')->name('post.index');
        Route::get('banners', PagePlaceholderController::class)->defaults('screenId', 'A-13')->name('banner.index');
        Route::get('admins', PagePlaceholderController::class)->defaults('screenId', 'A-14')->name('account.index');
        Route::get('password', PagePlaceholderController::class)->defaults('screenId', 'A-15')->name('password.edit');
        Route::get('gate', PagePlaceholderController::class)->defaults('screenId', 'A-16')->name('gate.index');
    });
});
