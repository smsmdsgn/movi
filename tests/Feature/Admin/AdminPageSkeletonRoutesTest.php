<?php

use App\Enums\AdminRole;

it('管理画面（4.8.5 A-10〜A-16）のルートが認証済み管理者に画面IDを返す', function (string $routeName, string $screenId) {
    $this->actingAs(createAdmin(), 'admin')
        ->get(route($routeName))
        ->assertOk()
        ->assertSee($screenId);
})->with([
    'A-10 予約状況' => ['admin.reservation.index', 'A-10'],
    'A-11 予約検索' => ['admin.reservation.search', 'A-11'],
    'A-12 お知らせ' => ['admin.post.index', 'A-12'],
    'A-13 バナー' => ['admin.banner.index', 'A-13'],
    'A-14 管理者アカウント' => ['admin.account.index', 'A-14'],
    'A-15 パスワード変更' => ['admin.password.edit', 'A-15'],
    'A-16 入場ゲート' => ['admin.gate.index', 'A-16'],
]);

it('cinema-admin はバナー・管理者アカウント管理を除く全画面へ到達できる（4.8.2）', function (string $routeName, string $screenId) {
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin')
        ->get(route($routeName))
        ->assertOk()
        ->assertSee($screenId);
})->with([
    'A-10 予約状況' => ['admin.reservation.index', 'A-10'],
    'A-11 予約検索' => ['admin.reservation.search', 'A-11'],
    'A-12 お知らせ' => ['admin.post.index', 'A-12'],
    'A-15 パスワード変更' => ['admin.password.edit', 'A-15'],
    'A-16 入場ゲート' => ['admin.gate.index', 'A-16'],
]);

it('管理画面ダッシュボード（A-02）が認証済み管理者に表示される', function () {
    $admin = createAdmin();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee($admin->name);
});

it('cinema-admin はダッシュボード（A-02）へ到達できる（4.8.2）', function () {
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk();
});

it('未ログインで管理画面配下にアクセスすると admin.login へリダイレクトされる', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('admin.login'));
})->with([
    'A-02 ダッシュボード' => ['admin.dashboard'],
    'A-03 館マスタ' => ['admin.cinema.index'],
    'A-04 シアター・座席' => ['admin.theater.index'],
    'A-05 映画マスタ' => ['admin.movie.index'],
    'A-06 上映規格マスタ' => ['admin.format.index'],
    'A-07 券種・料金マスタ' => ['admin.ticket-type.index'],
    'A-08 上映編成' => ['admin.booking.index'],
    'A-09 上映回' => ['admin.screening.index'],
]);

it('gate ロールは入場ゲート以外の管理画面へ到達できない（T-12 / 17.1.3）', function (string $routeName) {
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin')
        ->get(route($routeName))
        ->assertForbidden();
})->with([
    'A-02 ダッシュボード' => ['admin.dashboard'],
    'A-03 館マスタ' => ['admin.cinema.index'],
    'A-04 シアター・座席' => ['admin.theater.index'],
    'A-05 映画マスタ' => ['admin.movie.index'],
    'A-06 上映規格マスタ' => ['admin.format.index'],
    'A-07 券種・料金マスタ' => ['admin.ticket-type.index'],
    'A-08 上映編成' => ['admin.booking.index'],
    'A-09 上映回' => ['admin.screening.index'],
    'A-13 バナー' => ['admin.banner.index'],
]);

it('gate ロールは入場ゲート画面へ到達できる（T-12）', function () {
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin')
        ->get(route('admin.gate.index'))
        ->assertOk();
});

it('cinema-admin はバナー・管理者アカウント管理へ到達できない（4.8.2）', function (string $routeName) {
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin')
        ->get(route($routeName))
        ->assertForbidden();
})->with([
    'A-13 バナー' => ['admin.banner.index'],
    'A-14 管理者アカウント' => ['admin.account.index'],
]);

it('cinema-admin にはサイドバーのバナー・管理者アカウント管理が表示されない（4.8.2）', function () {
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee(__('admin.nav.banners'))
        ->assertDontSee(__('admin.nav.admins'));
});

it('gate ロールのサイドバーには入場ゲート以外の項目が表示されない（T-12）', function () {
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin')
        ->get(route('admin.gate.index'))
        ->assertOk()
        ->assertDontSee(__('admin.nav.dashboard'))
        ->assertDontSee(__('admin.nav.banners'))
        ->assertSee(__('admin.nav.gate'));
});
