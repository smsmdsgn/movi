<?php

use App\Enums\AdminRole;
use App\Livewire\Admin\Formats\Index;
use App\Models\Format;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('super-admin は上映規格一覧を閲覧でき、編集ボタンが表示される（4.8.2）', function () {
    Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.format.index'))
        ->assertOk()
        ->assertSee('2D')
        ->assertSee(__('admin.format.actions.edit'))
        ->assertSee(__('admin.format.surcharge_notice'));
});

it('cinema-admin は一覧を閲覧できるが編集ボタンが表示されない（4.8.2）', function () {
    Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin')
        ->get(route('admin.format.index'))
        ->assertOk()
        ->assertSee('2D')
        ->assertDontSee(__('admin.format.actions.edit'));
});

it('super-admin は既定追加料金を編集できる（4.8.2）', function () {
    $format = Format::create(['name' => 'MOVI GRAND', 'default_surcharge' => 800]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editFormat', $format->id)
        ->set('default_surcharge', 900)
        ->call('saveFormat')
        ->assertHasNoErrors()
        ->assertSet('showEditForm', false);

    expect($format->fresh()->default_surcharge)->toBe(900);
    expect($format->fresh()->name)->toBe('MOVI GRAND');
});

it('規格名は編集対象外で、画面から変更できない（6.6 / 4.8.6追記表）', function () {
    // シーダー（MasterDataSeeder等）が規格を名称で引くため、改名を許すと
    // 再シード時に6件目が生成され6.6の固定集合が崩れる。
    $this->withoutExceptionHandling();
    $format = Format::create(['name' => 'MOVI GRAND', 'default_surcharge' => 800]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editFormat', $format->id)
        ->set('name', 'MOVI GRAND改');
})->throws(CannotUpdateLockedPropertyException::class);

it('cinema-admin は editFormat を実行できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    Livewire::test(Index::class)->call('editFormat', $format->id);
})->throws(AuthorizationException::class);

it('editFormat を経由せず saveFormat を直接呼んでも何も更新されない', function () {
    $this->withoutExceptionHandling();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $this->actingAs(createAdmin(), 'admin');

    // showEditFormはwire:model.selfで二方向バインドするためLockedにできないが、
    // editingFormatId（Locked）が既定値のままなら更新対象を特定できず何もしない。
    Livewire::test(Index::class)
        ->set('showEditForm', true)
        ->set('default_surcharge', 777)
        ->call('saveFormat')
        ->assertHasNoErrors();

    expect($format->fresh()->default_surcharge)->toBe(0);
});

it('saveFormat は呼び出しのたびに認可する（4.8.2 / 4.8.6追記表）', function () {
    // editFormat での認可だけに頼っていないことを固定する。編集対象を保持した
    // 状態で権限の無い管理者に切り替え、saveFormat 側の authorize が働くか見る。
    $this->withoutExceptionHandling();
    $format = Format::create(['name' => '2D', 'default_surcharge' => 0]);
    $this->actingAs(createAdmin(), 'admin');

    $component = Livewire::test(Index::class)
        ->call('editFormat', $format->id)
        ->set('default_surcharge', 500);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    $component->call('saveFormat');
})->throws(AuthorizationException::class);

it('default_surcharge が負数の場合はバリデーションエラーになる', function () {
    $format = Format::create(['name' => 'MOVI MOTION', 'default_surcharge' => 1000]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editFormat', $format->id)
        ->set('default_surcharge', -1)
        ->call('saveFormat')
        ->assertHasErrors(['default_surcharge']);
});

it('default_surcharge が上限を超える場合はバリデーションエラーになる（4.8.6追記表）', function () {
    // 上限が無いと unsignedInteger の桁数超過でMariaDBのstrictモードが500を返す。
    $format = Format::create(['name' => 'MOVI MOTION', 'default_surcharge' => 1000]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editFormat', $format->id)
        ->set('default_surcharge', 10001)
        ->call('saveFormat')
        ->assertHasErrors(['default_surcharge']);

    expect($format->fresh()->default_surcharge)->toBe(1000);
});

it('境界値（0円・上限額）は保存できる（6.6）', function (int $surcharge) {
    // 2D の追加料金0円は 6.6 の実データ。min:1 等への書き換えを検出する。
    $format = Format::create(['name' => 'MOVI VIVID', 'default_surcharge' => 800]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editFormat', $format->id)
        ->set('default_surcharge', $surcharge)
        ->call('saveFormat')
        ->assertHasNoErrors();

    expect($format->fresh()->default_surcharge)->toBe($surcharge);
})->with([
    '下限（0円）' => 0,
    '上限（10,000円）' => 10000,
]);

it('gate ロールは上映規格一覧を取得できない（4.8.2 / 17.1.3）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);
