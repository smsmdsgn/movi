<?php

use App\Enums\AdminRole;
use App\Livewire\Admin\Cinemas\Index;
use App\Models\Cinema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('super-admin は全館の館マスタを閲覧できる（4.8.2）', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.cinema.index'))
        ->assertOk()
        ->assertSee('祇園ムビ')
        ->assertSee('ムビ京都');
});

it('cinema-admin は自館の館マスタのみ閲覧できる（4.8.2）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin')
        ->get(route('admin.cinema.index'))
        ->assertOk()
        ->assertSee('祇園ムビ')
        ->assertDontSee('ムビ京都');
});

it('所属館が未設定の cinema-admin が館マスタ一覧を開くと403になる', function () {
    createCinema('gion', '祇園ムビ');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, null), 'admin')
        ->get(route('admin.cinema.index'))
        ->assertForbidden();
});

it('cinema-admin には新規作成・編集の操作が表示されない（4.8.2）', function () {
    $gion = createCinema('gion', '祇園ムビ');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin')
        ->get(route('admin.cinema.index'))
        ->assertOk()
        ->assertDontSee(__('admin.cinema.actions.create'))
        ->assertDontSee(__('admin.cinema.actions.edit'));
});

it('cinema-admin は自館の館マスタを閲覧専用で開ける（4.8.2）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $gion->update(['facility_info' => '売店・自動精算機あり']);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    Livewire::test(Index::class)
        ->call('view', $gion->id)
        ->assertSet('readOnly', true)
        ->assertSet('facility_info', '売店・自動精算機あり')
        ->assertSee('売店・自動精算機あり');
});

it('cinema-admin は他館の館マスタを閲覧できない（4.8.2 / 17.2.1）', function () {
    $this->withoutExceptionHandling();
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    Livewire::test(Index::class)->call('view', $kyoto->id);
})->throws(ModelNotFoundException::class);

it('gate ロールは館マスタの一覧・詳細を取得できない（4.8.2 / 17.1.3）', function () {
    $this->withoutExceptionHandling();
    $gion = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(AdminRole::Gate, $gion), 'admin');

    // `AuthorizeAdminScreen` はフルページロードのみを保護するため（4.8.6追記表）、
    // ルートを経由しないコンポーネント直接呼び出しでも viewAny で拒否されることを確認する。
    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('gate ロールは view() を直接呼んでも館マスタを取得できない（4.8.2 / 17.1.3）', function () {
    $this->withoutExceptionHandling();
    $gion = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(AdminRole::Gate, $gion), 'admin');

    Livewire::test(Index::class)->call('view', $gion->id);
})->throws(AuthorizationException::class);

it('super-admin は館マスタを新規作成できる（4.8.2）', function () {
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validCinemaForm())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect(Cinema::where('slug', 'shijo-karasuma')->exists())->toBeTrue();
});

it('super-admin は館マスタを編集できる（4.8.2）', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('edit', $cinema->id)
        ->set('name', '祇園ムビ（改装）')
        ->call('save')
        ->assertHasNoErrors();

    expect($cinema->fresh()->name)->toBe('祇園ムビ（改装）');
});

it('cinema-admin は館マスタの作成を実行できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $gion = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    Livewire::test(Index::class)->call('create');
})->throws(AuthorizationException::class);

it('cinema-admin は館マスタの編集を実行できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $gion = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    Livewire::test(Index::class)->call('edit', $gion->id);
})->throws(AuthorizationException::class);

it('cinema-admin は create を経由せず save を直接呼んでも作成できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $gion = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    Livewire::test(Index::class)->set(validCinemaForm())->call('save');
})->throws(AuthorizationException::class);

it('slugが形式（4.1.3-6）に合わない場合はバリデーションエラーになる', function () {
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validCinemaForm(['slug' => 'Shijo_Karasuma']))
        ->call('save')
        ->assertHasErrors(['slug']);
});

it('slugの末尾に改行を含む場合はバリデーションエラーになる（ルート制約との不一致防止）', function () {
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validCinemaForm(['slug' => "shijo-karasuma\n"]))
        ->call('save')
        ->assertHasErrors(['slug']);
});

it('slugが既存の館と重複する場合はバリデーションエラーになる', function () {
    createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validCinemaForm(['slug' => 'gion']))
        ->call('save')
        ->assertHasErrors(['slug']);
});

it('編集時は自分自身の既存slugを重複エラーにしない', function () {
    $cinema = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('edit', $cinema->id)
        ->set('phone', '075-111-1111')
        ->call('save')
        ->assertHasNoErrors();
});

it('map_embed_urlがGoogleマップ以外のホストの場合はバリデーションエラーになる（17.7 CSP）', function () {
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('create')
        ->set(validCinemaForm(['map_embed_url' => 'https://example.com/map']))
        ->call('save')
        ->assertHasErrors(['map_embed_url']);
});

it('map_embed_urlが255文字を超えても保存できる（m_cinemas.map_embed_urlのTEXT化の回帰確認）', function () {
    $this->actingAs(createAdmin(), 'admin');

    $longUrl = 'https://www.google.com/maps/embed?pb='.str_repeat('a', 400);
    expect(strlen($longUrl))->toBeGreaterThan(255);

    Livewire::test(Index::class)
        ->call('create')
        ->set(validCinemaForm(['map_embed_url' => $longUrl]))
        ->call('save')
        ->assertHasNoErrors();

    expect(Cinema::where('slug', 'shijo-karasuma')->first()?->map_embed_url)->toBe($longUrl);
});
