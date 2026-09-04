<?php

use App\Enums\AdminRole;
use App\Livewire\Admin\TicketTypes\Index;
use App\Models\TicketType;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('super-admin は券種一覧を閲覧でき、編集ボタンと注意書きが表示される（4.8.2）', function () {
    TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.ticket-type.index'))
        ->assertOk()
        ->assertSee('大人')
        ->assertSee(__('admin.ticket_type.actions.edit'))
        ->assertSee(__('admin.ticket_type.price_notice'));
});

it('cinema-admin は一覧を閲覧できるが編集ボタンが表示されない（4.8.2）', function () {
    TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin')
        ->get(route('admin.ticket-type.index'))
        ->assertOk()
        ->assertSee('大人')
        ->assertDontSee(__('admin.ticket_type.actions.edit'));
});

it('super-admin は価格と適用条件を編集できる（4.8.2）', function () {
    $ticketType = TicketType::create(['name' => '学生', 'price' => 1500, 'display_order' => 2, 'condition' => '要学生証']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('price', 1600)
        ->set('condition', '大学生・専門学校生。要学生証')
        ->call('saveTicketType')
        ->assertHasNoErrors()
        ->assertSet('showEditForm', false);

    expect($ticketType->fresh()->price)->toBe(1600);
    expect($ticketType->fresh()->condition)->toBe('大学生・専門学校生。要学生証');
    expect($ticketType->fresh()->name)->toBe('学生');
    expect($ticketType->fresh()->display_order)->toBe(2);
});

it('券種名は編集対象外で、画面から変更できない（6.5.1 / 4.8.6追記表）', function () {
    // シーダーが券種を名称で引くため、改名を許すと再シード時に6件目が生成され
    // 6.5.1の固定集合が崩れる。
    $this->withoutExceptionHandling();
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('name', '大人改');
})->throws(CannotUpdateLockedPropertyException::class);

it('cinema-admin は editTicketType を実行できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    Livewire::test(Index::class)->call('editTicketType', $ticketType->id);
})->throws(AuthorizationException::class);

it('editTicketType を経由せず saveTicketType を直接呼んでも何も更新されない', function () {
    $this->withoutExceptionHandling();
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    // showEditFormはwire:model.selfで二方向バインドするためLockedにできないが、
    // editingTicketTypeId（Locked）が既定値のままなら更新対象を特定できず何もしない。
    Livewire::test(Index::class)
        ->set('showEditForm', true)
        ->set('price', 3000)
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->price)->toBe(2000);
});

it('saveTicketType は呼び出しのたびに認可する（4.8.2 / 4.8.6追記表）', function () {
    // editTicketType での認可だけに頼っていないことを固定する。編集対象を保持した
    // 状態で権限の無い管理者に切り替え、saveTicketType側のauthorizeが働くか見る。
    $this->withoutExceptionHandling();
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    $component = Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('price', 2500);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    $component->call('saveTicketType');
})->throws(AuthorizationException::class);

it('price が0の場合はバリデーションエラーになる（4.8.6追記表）', function () {
    // 無料鑑賞券（4.5.2）との役割重複、および0円決済の回避のため下限は1円。
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('price', 0)
        ->call('saveTicketType')
        ->assertHasErrors(['price']);
});

it('price が上限を超える場合はバリデーションエラーになる（4.8.6追記表）', function () {
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('price', 10001)
        ->call('saveTicketType')
        ->assertHasErrors(['price']);

    expect($ticketType->fresh()->price)->toBe(2000);
});

it('境界値（1円・10,000円）は保存できる（4.8.6追記表）', function (int $price) {
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('price', $price)
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->price)->toBe($price);
})->with([
    '下限（1円）' => 1,
    '上限（10,000円）' => 10000,
]);

it('condition が256文字の場合はバリデーションエラーになる（4.8.6追記表）', function () {
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('condition', str_repeat('あ', 256))
        ->call('saveTicketType')
        ->assertHasErrors(['condition']);
});

it('condition を空白のみにするとnullで保存される（4.8.6追記表）', function () {
    // 6.5.1 の「高校生以下」「障がい者手帳をお持ちの方」は条件欄が空であり、
    // 条件を消す操作が空文字ではなくnullとして保存されることを固定する。
    $ticketType = TicketType::create(['name' => '学生', 'price' => 1500, 'display_order' => 2, 'condition' => '要学生証']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('condition', '   ')
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->condition)->toBeNull();
});

it('condition の前後の空白は除去して保存される', function (string $input) {
    // 全角空白（U+3000）は trim() も正規表現の \s も対象にしないため、
    // 日本語入力で混入した場合に案内文の先頭がずれる。
    $ticketType = TicketType::create(['name' => 'シニア', 'price' => 1500, 'display_order' => 4, 'condition' => '65歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('condition', $input)
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->condition)->toBe('60歳以上');
})->with([
    '半角空白' => '  60歳以上  ',
    '全角空白' => '　60歳以上　',
]);

it('condition が全角空白のみの場合もnullで保存される', function () {
    $ticketType = TicketType::create(['name' => '学生', 'price' => 1500, 'display_order' => 2, 'condition' => '要学生証']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('condition', '　　')
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->condition)->toBeNull();
});

it('condition は255文字まで保存できる（境界値）', function () {
    // 長さの検証は前後の空白を除去したあとに掛ける。
    $ticketType = TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTicketType', $ticketType->id)
        ->set('condition', ' '.str_repeat('あ', 255).' ')
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($ticketType->fresh()->condition)->toBe(str_repeat('あ', 255));
});

it('一覧は display_order の昇順で表示される（6.5.1）', function () {
    // 6.5.1 の列挙順が画面の並び順である。id 順への退化を検出する。
    TicketType::create(['name' => 'シニア', 'price' => 1500, 'display_order' => 4, 'condition' => '65歳以上']);
    TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.ticket-type.index'))
        ->assertOk()
        ->assertSeeInOrder(['大人', 'シニア']);
});

it('gate ロールは券種一覧を取得できない（4.8.2 / 17.1.3）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);
