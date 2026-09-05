<?php

use App\Enums\AdminRole;
use App\Livewire\Admin\Accounts\Index;
use App\Livewire\Admin\Auth\Login;
use App\Models\Admin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('super-admin は管理者アカウント一覧を閲覧できる（4.8.2）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    createAdmin(AdminRole::CinemaAdmin, $cinema);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.account.index'))
        ->assertOk()
        ->assertSee('テスト管理者')
        ->assertSee(__('admin.role.cinema-admin'));
});

it('super-admin が管理者を新規発行できる（4.8.4）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validAccountForm(['cinema_id' => (string) $cinema->id]))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $created = Admin::where('login_id', 'gion-admin')->sole();

    expect($created->role)->toBe(AdminRole::CinemaAdmin)
        ->and($created->cinema_id)->toBe($cinema->id)
        ->and($created->is_active)->toBeTrue()
        ->and(Hash::check('correct-horse-battery', $created->password))->toBeTrue();
});

it('cinema-admin・gate には所属館が必須（4.8.6 CinemaScope の403を作らない）', function (AdminRole $role) {
    createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validAccountForm(['role' => $role->value, 'cinema_id' => '']))
        ->call('save')
        ->assertHasErrors(['cinema_id' => 'required']);

    expect(Admin::where('login_id', 'gion-admin')->exists())->toBeFalse();
})->with([
    'cinema-admin' => [AdminRole::CinemaAdmin],
    'gate' => [AdminRole::Gate],
]);

it('super-admin は所属館を持てない（4.8.2 全館横断）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validAccountForm([
            'role' => AdminRole::SuperAdmin->value,
            'cinema_id' => (string) $cinema->id,
        ]))
        ->call('save')
        ->assertHasErrors(['cinema_id' => 'prohibited']);
});

it('役割を super-admin へ切り替えると選択済みの所属館が解除される', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set('cinema_id', (string) $cinema->id)
        ->set('role', AdminRole::SuperAdmin->value)
        ->assertSet('cinema_id', '');
});

it('パスワードは12文字以上でなければならない（17.1.2-3）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validAccountForm([
            'cinema_id' => (string) $cinema->id,
            'password' => 'short-pass1',
            'password_confirmation' => 'short-pass1',
        ]))
        ->call('save')
        ->assertHasErrors(['password']);
});

it('パスワードは確認入力と一致しなければならない', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validAccountForm([
            'cinema_id' => (string) $cinema->id,
            'password_confirmation' => 'different-password',
        ]))
        ->call('save')
        ->assertHasErrors(['password' => 'confirmed']);
});

it('編集で空白のみのパスワードを入力しても保存されない（17.1.2-3）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $target = createAdmin(AdminRole::CinemaAdmin, $cinema);
    $this->actingAs(createAdmin(), 'admin');

    // バリデータは空白のみの文字列を「空」とみなして Password::min(12) と
    // confirmed を飛ばす。保存側が厳密な空文字比較だと素通りして保存される。
    Livewire::test(Index::class)
        ->call('edit', $target->id)
        ->set('password', '   ')
        ->set('password_confirmation', 'まったく別の値')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('   ', $target->refresh()->password))->toBeFalse()
        ->and(Hash::check('password', $target->password))->toBeTrue();
});

it('ログインIDは半角英小文字・数字とハイフンに限られる（4.8.4-1）', function (string $loginId) {
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validAccountForm([
            'login_id' => $loginId,
            'cinema_id' => (string) $cinema->id,
        ]))
        ->call('save')
        ->assertHasErrors(['login_id' => 'regex']);
})->with([
    '大文字' => ['Gion-Admin'],
    'アンダースコア' => ['gion_admin'],
    '先頭ハイフン' => ['-gion'],
    '連続ハイフン' => ['gion--admin'],
    // `^`/`$` は末尾の改行の直前にもマッチするため、アンカーに `\A`/`\z` を
    // 使っていないとこのケースだけが通過する（A-03 の slug と同じ理由）。
    '末尾の改行' => ["gion-admin\n"],
]);

it('存在しない館は所属館に指定できない', function () {
    createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validAccountForm(['cinema_id' => '999999']))
        ->call('save')
        ->assertHasErrors(['cinema_id' => 'exists']);
});

it('編集経路でも super-admin へ変更した管理者は所属館を持てない', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $target = createAdmin(AdminRole::CinemaAdmin, $cinema);
    $this->actingAs(createAdmin(), 'admin');

    // 役割セレクタ（wire:model.live）による所属館の解除をすり抜けた状態を模す。
    Livewire::test(Index::class)
        ->call('edit', $target->id)
        ->set('role', AdminRole::SuperAdmin->value)
        ->set('cinema_id', (string) $cinema->id)
        ->call('save')
        ->assertHasErrors(['cinema_id' => 'prohibited']);

    expect($target->refresh()->role)->toBe(AdminRole::CinemaAdmin);
});

it('編集で所属館を変更できる', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $target = createAdmin(AdminRole::CinemaAdmin, $gion);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('edit', $target->id)
        ->set('cinema_id', (string) $kyoto->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($target->refresh()->cinema_id)->toBe($kyoto->id);
});

it('ログインIDは重複できない', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    Admin::create([
        'login_id' => 'gion-admin',
        'password' => 'correct-horse-battery',
        'name' => '既存',
        'role' => AdminRole::CinemaAdmin,
        'cinema_id' => $cinema->id,
        'is_active' => true,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validAccountForm(['cinema_id' => (string) $cinema->id]))
        ->call('save')
        ->assertHasErrors(['login_id' => 'unique']);
});

it('編集でパスワードを空欄にすると据え置かれる（4.8.4-5）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $target = createAdmin(AdminRole::CinemaAdmin, $cinema);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('edit', $target->id)
        ->set('name', '改名後の管理者')
        ->set('password', '')
        ->set('password_confirmation', '')
        ->call('save')
        ->assertHasNoErrors();

    $target->refresh();

    expect($target->name)->toBe('改名後の管理者')
        ->and(Hash::check('password', $target->password))->toBeTrue();
});

it('編集でパスワードを入力すると再設定される（4.8.4-5）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $target = createAdmin(AdminRole::CinemaAdmin, $cinema);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('edit', $target->id)
        ->set('password', 'brand-new-password')
        ->set('password_confirmation', 'brand-new-password')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('brand-new-password', $target->refresh()->password))->toBeTrue();
});

it('無効化された管理者は物理削除されず is_active が落ちる（4.8.4-7）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $target = createAdmin(AdminRole::CinemaAdmin, $cinema);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)->call('toggleActive', $target->id);

    expect(Admin::whereKey($target->id)->exists())->toBeTrue()
        ->and($target->refresh()->is_active)->toBeFalse();

    Livewire::test(Index::class)->call('toggleActive', $target->id);

    expect($target->refresh()->is_active)->toBeTrue();
});

it('無効化された管理者はログインできない（17.1.2-6）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $target = createAdmin(AdminRole::CinemaAdmin, $cinema);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)->call('toggleActive', $target->id);

    Auth::guard('admin')->logout();

    Livewire::test(Login::class)
        ->set('login_id', $target->login_id)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('login_id');

    expect(Auth::guard('admin')->check())->toBeFalse();
});

it('無効化された管理者は、ログイン中でも管理画面を操作できない（17.1.2-6）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $target = createAdmin(AdminRole::CinemaAdmin, $cinema);

    // 無効化の前に開いていたページからの操作を模す。
    $this->actingAs(createAdmin(), 'admin');
    Livewire::test(Index::class)->call('toggleActive', $target->id);

    $this->actingAs($target->refresh(), 'admin')
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.login'));
});

it('無効化された super-admin は他の super-admin を無効化できない（有効な super-admin を0人にしない）', function () {
    $this->withoutExceptionHandling();
    $first = createAdmin();
    $second = createAdmin();

    // 1人目が2人目を無効化する。2人目のセッションはこの時点では残っている。
    $this->actingAs($first, 'admin');
    Livewire::test(Index::class)->call('toggleActive', $second->id);
    expect($second->refresh()->is_active)->toBeFalse();

    // 無効化された2人目が1人目を無効化できてはならない。
    $this->actingAs($second, 'admin');
    Livewire::test(Index::class)->call('toggleActive', $first->id);
})->throws(AuthorizationException::class);

it('自分自身を無効化できない（自らを締め出さないため）', function () {
    $this->withoutExceptionHandling();
    $me = createAdmin();
    $this->actingAs($me, 'admin');

    Livewire::test(Index::class)->call('toggleActive', $me->id);
})->throws(AuthorizationException::class);

it('自分自身の役割を変更できない（4.8.4-4 復旧導線が無いため）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $me = createAdmin();
    $this->actingAs($me, 'admin');

    Livewire::test(Index::class)
        ->call('edit', $me->id)
        ->set('role', AdminRole::CinemaAdmin->value)
        ->set('cinema_id', (string) $cinema->id)
        ->call('save')
        ->assertHasErrors(['role']);

    expect($me->refresh()->role)->toBe(AdminRole::SuperAdmin);
});

it('自分自身の氏名・パスワードは変更できる', function () {
    $me = createAdmin();
    $this->actingAs($me, 'admin');

    Livewire::test(Index::class)
        ->call('edit', $me->id)
        ->set('name', '本部 太郎')
        ->set('password', 'my-new-password-1')
        ->set('password_confirmation', 'my-new-password-1')
        ->call('save')
        ->assertHasNoErrors();

    expect($me->refresh()->name)->toBe('本部 太郎');
});

it('自分自身の行には有効／無効の切替が表示されず、他の管理者の行には表示される', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $me = createAdmin();
    $other = createAdmin(AdminRole::CinemaAdmin, $cinema);

    // 案内文（admin.account.notice）にも「無効化」の語が含まれるため、
    // ラベルではなく行ごとのアクション呼び出しの有無で判定する。
    $this->actingAs($me, 'admin')
        ->get(route('admin.account.index'))
        ->assertOk()
        ->assertDontSee("toggleActive({$me->id})", escape: false)
        ->assertSee("toggleActive({$other->id})", escape: false);
});

it('cinema-admin は管理者アカウント一覧を取得できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $cinema), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);
