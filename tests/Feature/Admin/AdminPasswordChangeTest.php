<?php

use App\Enums\AdminRole;
use App\Livewire\Admin\Password\Edit;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('管理者が自分自身のパスワードを変更できる（4.8.2）', function (AdminRole $role) {
    $admin = createAdmin($role, $role === AdminRole::SuperAdmin ? null : createCinema());
    $other = createAdmin();
    $this->actingAs($admin, 'admin');

    Livewire::test(Edit::class)
        ->set(validPasswordChangeForm())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('current_password', '')
        ->assertSet('password', '');

    expect(Hash::check('correct-horse-battery', $admin->fresh()->password))->toBeTrue()
        ->and(Hash::check('password', $other->fresh()->password))->toBeTrue();
})->with([
    'super-admin' => [AdminRole::SuperAdmin],
    'cinema-admin' => [AdminRole::CinemaAdmin],
]);

it('ハッシュ形式に見える文字列を新しいパスワードにしても、ハッシュ化して保存し照合できる（旧12章 残課題20）', function (int $extraCost) {
    // `hashed` キャストはハッシュ済みに見える値を再ハッシュせずに保存し、設定より高い
    // cost の値では例外（500）で止まる。保存側でハッシュ化しないと、前者は平文のまま
    // 格納されて照合に失敗し、後者は変更自体ができない（4.8.4-4 / 4.8.6追記表）。
    $cost = (int) config('hashing.bcrypt.rounds') + $extraCost;
    $looksHashed = Hash::make('any-seed-password', ['rounds' => $cost]);
    $admin = createAdmin();
    $this->actingAs($admin, 'admin');

    Livewire::test(Edit::class)
        ->set(validPasswordChangeForm(['password' => $looksHashed, 'password_confirmation' => $looksHashed]))
        ->call('save')
        ->assertHasNoErrors();

    $stored = $admin->fresh()->password;

    expect($stored)->not->toBe($looksHashed)
        ->and(Hash::check($looksHashed, $stored))->toBeTrue();
})->with([
    '設定と同じ cost（そのまま保存される経路）' => [0],
    '設定より高い cost（例外になる経路）' => [1],
]);

it('cinema-admin もパスワード変更画面へ到達できる（4.8.2）', function () {
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin')
        ->get(route('admin.password.edit'))
        ->assertOk()
        ->assertSee(__('admin.password.notice'))
        ->assertSee(__('admin.password.fields.current_password'));
});

it('現在のパスワードが誤っていると変更できない（4.8.4-4 唯一の本人確認）', function () {
    $admin = createAdmin();
    $this->actingAs($admin, 'admin');

    Livewire::test(Edit::class)
        ->set(validPasswordChangeForm(['current_password' => 'wrong-password']))
        ->call('save')
        ->assertHasErrors(['current_password' => 'current_password'])
        ->assertSet('current_password', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');

    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();
});

it('新しいパスワードが12文字未満だと変更できない（17.1.2-3）', function () {
    $admin = createAdmin();
    $this->actingAs($admin, 'admin');

    Livewire::test(Edit::class)
        ->set(validPasswordChangeForm([
            'password' => 'short-11chr',
            'password_confirmation' => 'short-11chr',
        ]))
        ->call('save')
        ->assertHasErrors(['password']);

    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();
});

it('確認用の入力が一致しないと変更できない', function () {
    $admin = createAdmin();
    $this->actingAs($admin, 'admin');

    Livewire::test(Edit::class)
        ->set(validPasswordChangeForm(['password_confirmation' => 'different-password']))
        ->call('save')
        ->assertHasErrors(['password' => 'confirmed']);

    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();
});

it('現在と同じパスワードには変更できない', function () {
    $admin = createAdmin();
    $this->actingAs($admin, 'admin');

    Livewire::test(Edit::class)
        ->set(validPasswordChangeForm([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))
        ->call('save')
        ->assertHasErrors(['password' => 'different']);

    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();
});

it('gate ロールは自分のパスワードを変更できない（17.1.3）', function () {
    $admin = createAdmin(AdminRole::Gate, createCinema());
    $this->actingAs($admin, 'admin');

    Livewire::test(Edit::class)
        ->set(validPasswordChangeForm())
        ->call('save')
        ->assertForbidden();

    expect(Hash::check('password', Admin::findOrFail($admin->id)->password))->toBeTrue();
});

it('無効化された管理者はパスワードを変更できない（17.1.2-6）', function () {
    $admin = createAdmin();
    $this->actingAs($admin, 'admin');
    $admin->update(['is_active' => false]);

    Livewire::test(Edit::class)
        ->set(validPasswordChangeForm())
        ->call('save')
        ->assertForbidden();

    expect(Hash::check('password', Admin::findOrFail($admin->id)->password))->toBeTrue();
});
