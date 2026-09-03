<?php

use App\Enums\AdminRole;
use App\Livewire\Admin\Auth\Login;
use Livewire\Livewire;

it('ログイン画面が表示される', function () {
    $this->get(route('admin.login'))->assertOk();
});

it('正しい認証情報でログインできる（17.1.2）', function () {
    $admin = createAdmin();

    Livewire::test(Login::class)
        ->set('login_id', $admin->login_id)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($admin, 'admin');
});

it('誤ったパスワードではログインできない', function () {
    $admin = createAdmin();

    Livewire::test(Login::class)
        ->set('login_id', $admin->login_id)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('login_id');

    $this->assertGuest('admin');
});

it('無効化された管理者はログインできない（17.1.2-6）', function () {
    $admin = createAdmin();
    $admin->update(['is_active' => false]);

    Livewire::test(Login::class)
        ->set('login_id', $admin->login_id)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('login_id');

    $this->assertGuest('admin');
});

it('同一ログインIDへの5回の失敗でそれ以降ログインを拒否する（17.1.2-4）', function () {
    $admin = createAdmin();

    foreach (range(1, 5) as $_) {
        Livewire::test(Login::class)
            ->set('login_id', $admin->login_id)
            ->set('password', 'wrong-password')
            ->call('login');
    }

    Livewire::test(Login::class)
        ->set('login_id', $admin->login_id)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('login_id');

    $this->assertGuest('admin');
});

it('同一IPからログインIDを変えた5回の失敗でそれ以降ログインを拒否する（17.1.2-4）', function () {
    foreach (range(1, 5) as $_) {
        Livewire::test(Login::class)
            ->set('login_id', createAdmin()->login_id)
            ->set('password', 'wrong-password')
            ->call('login');
    }

    $admin = createAdmin();

    Livewire::test(Login::class)
        ->set('login_id', $admin->login_id)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('login_id');

    $this->assertGuest('admin');
});

it('ログイン済みで /admin/login にアクセスすると admin.dashboard へリダイレクトされる', function () {
    $admin = createAdmin();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.login'))
        ->assertRedirect(route('admin.dashboard'));
});

it('gate ロールはログイン成功時に入場ゲート画面へ遷移する（17.1.3）', function () {
    $admin = createAdmin(AdminRole::Gate);

    Livewire::test(Login::class)
        ->set('login_id', $admin->login_id)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('admin.gate.index'));
});

it('gate ロールがログイン済みで /admin/login にアクセスすると admin.gate.index へリダイレクトされる（ダッシュボードへの無限ループ防止）', function () {
    $admin = createAdmin(AdminRole::Gate);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.login'))
        ->assertRedirect(route('admin.gate.index'));
});

it('ログアウトできる', function () {
    $admin = createAdmin();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.logout'))
        ->assertRedirect(route('admin.login'));

    $this->assertGuest('admin');
});

it('管理者のログアウトはセッション内の他のデータ（顧客側の状態等）を破棄しない（17.1.2-1）', function () {
    $admin = createAdmin();

    $this->withSession(['front.test_marker' => 'kept'])
        ->actingAs($admin, 'admin')
        ->post(route('admin.logout'))
        ->assertSessionHas('front.test_marker', 'kept');
});

it('未ログインで /admin にアクセスすると admin.login へリダイレクトされる', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
});
