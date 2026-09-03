<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\PagePlaceholderController;
use App\Http\Middleware\AuthorizeAdminScreen;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Cinemas\Index as CinemaIndex;
use App\Livewire\Admin\Formats\Index as FormatIndex;
use App\Livewire\Admin\Movies\Index as MovieIndex;
use App\Livewire\Admin\Theaters\Index as TheaterIndex;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 管理画面（4.8.5 A-01〜A-16）
|--------------------------------------------------------------------------
|
| A-01（ログイン）・A-03（館マスタ）・A-04（シアター・座席）・A-05（映画マスタ）・
| A-06（上映規格マスタ）を実装。
| A-02（ダッシュボード）は最低限の実装、A-07〜A-16 は工程3-aでは
| ルート骨格のみで、PagePlaceholderController が画面IDのみを返す（12章 残課題）。
| 各画面の実装（該当フェーズ、11.1）で画面ごとの内容へ差し替える。
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
        Route::get('cinemas', CinemaIndex::class)->name('cinema.index');
        Route::get('theaters', TheaterIndex::class)->name('theater.index');
        Route::get('movies', MovieIndex::class)->name('movie.index');
        Route::get('formats', FormatIndex::class)->name('format.index');
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
