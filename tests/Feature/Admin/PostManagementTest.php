<?php

use App\Enums\AdminRole;
use App\Enums\PostStatus;
use App\Livewire\Admin\Posts\Index;
use App\Models\Post;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A-12（お知らせ）の検証。4.7.1 の抽出条件（`cinema_id IS NULL OR cinema_id = 対象館`）と
 * 4.7.4追記表「A-12 の館の範囲」（対象館の可否は `PostPolicy::assignCinema()` が判定する）
 * を対象とする。削除の操作は設けない（6.2）ためこの観点の検証は無い。
 */
it('super-admin は全館共通のお知らせを投稿でき、cinema_id が null になる（4.8.2）', function () {
    $admin = createAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createPost')
        ->set(validPostForm())
        ->call('save')
        ->assertHasNoErrors();

    $post = Post::sole();

    expect($post->cinema_id)->toBeNull()
        ->and($post->created_by_admin_id)->toBe($admin->id);
});

it('super-admin は特定館向けにお知らせを投稿できる（4.8.2）', function () {
    $cinema = createCinema();
    $admin = createAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createPost')
        ->set(validPostForm(['cinema_id' => (string) $cinema->id]))
        ->call('save')
        ->assertHasNoErrors();

    expect(Post::sole()->cinema_id)->toBe($cinema->id);
});

it('cinema-admin は自館向けにお知らせを投稿できる（4.8.2）', function () {
    $cinema = createCinema();
    $admin = createAdmin(AdminRole::CinemaAdmin, $cinema);

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createPost')
        ->set(validPostForm(['cinema_id' => (string) $cinema->id]))
        ->call('save')
        ->assertHasNoErrors();

    expect(Post::sole()->cinema_id)->toBe($cinema->id);
});

it('cinema-admin は他館を対象館に指定して投稿できない（4.7.4追記表）', function () {
    $own = createCinema('own-cinema', '自館');
    $other = createCinema('other-cinema', '他館');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own), 'admin');
    $this->withoutExceptionHandling();

    $component = Livewire::test(Index::class)
        ->call('createPost')
        ->set(validPostForm(['cinema_id' => (string) $other->id]));

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect(Post::count())->toBe(0);
});

it('cinema-admin は全館共通を対象館に指定して投稿できない（4.7.4追記表）', function () {
    $cinema = createCinema();
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $cinema), 'admin');
    $this->withoutExceptionHandling();

    $component = Livewire::test(Index::class)
        ->call('createPost')
        ->set(validPostForm(['cinema_id' => '']));

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect(Post::count())->toBe(0);
});

it('cinema-admin は全館共通のお知らせを編集できない（4.7.4追記表）', function () {
    $cinema = createCinema();
    $post = createPost(['cinema_id' => null]);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $cinema), 'admin');
    $this->withoutExceptionHandling();

    expect(fn () => Livewire::test(Index::class)->call('editPost', $post->id))
        ->toThrow(AuthorizationException::class);
});

it('cinema-admin の一覧には自館・全館共通のお知らせが並び、他館のお知らせは出ない（4.7.1）', function () {
    $own = createCinema('own-cinema', '自館');
    $other = createCinema('other-cinema', '他館');
    $ownPost = createPost(['cinema_id' => $own->id, 'title' => '自館のお知らせ']);
    $commonPost = createPost(['cinema_id' => null, 'title' => '全館共通のお知らせ']);
    $otherPost = createPost(['cinema_id' => $other->id, 'title' => '他館のお知らせ']);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own), 'admin')
        ->get(route('admin.post.index'))
        ->assertOk()
        ->assertSee($ownPost->title)
        ->assertSee($commonPost->title)
        ->assertDontSee($otherPost->title);
});

it('super-admin が館を選ぶと、その館と全館共通のお知らせのみが並ぶ（4.7.1）', function () {
    $target = createCinema('target-cinema', '対象館');
    $other = createCinema('other-cinema', '他館');
    $targetPost = createPost(['cinema_id' => $target->id, 'title' => '対象館のお知らせ']);
    $commonPost = createPost(['cinema_id' => null, 'title' => '全館共通のお知らせ']);
    $otherPost = createPost(['cinema_id' => $other->id, 'title' => '他館のお知らせ']);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->set('selectedCinemaId', $target->id)
        ->assertSee($targetPost->title)
        ->assertSee($commonPost->title)
        ->assertDontSee($otherPost->title);
});

it('状態が「公開」で公開日時が空の場合はエラーになり、「下書き」なら空でも保存できる（4.7.4追記表）', function () {
    $admin = createAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createPost')
        ->set(validPostForm(['status' => PostStatus::Published->value, 'published_at' => '']))
        ->call('save')
        ->assertHasErrors('published_at');

    expect(Post::count())->toBe(0);

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createPost')
        ->set(validPostForm(['status' => PostStatus::Draft->value, 'published_at' => '']))
        ->call('save')
        ->assertHasNoErrors();

    expect(Post::sole()->published_at)->toBeNull();
});

it('カテゴリー・状態の絞り込みが効く（4.8.2）', function () {
    $notice = createPostCategory('notice', 'お知らせ');
    $campaign = createPostCategory('campaign', 'キャンペーン');
    $draftPost = createPost(['category_id' => $notice->id, 'status' => PostStatus::Draft, 'published_at' => null, 'title' => '下書きのお知らせ']);
    $publishedNotice = createPost(['category_id' => $notice->id, 'status' => PostStatus::Published, 'title' => '公開済みのお知らせ']);
    $publishedCampaign = createPost(['category_id' => $campaign->id, 'status' => PostStatus::Published, 'title' => '公開済みのキャンペーン']);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->set('filterCategoryId', $campaign->id)
        ->assertSee($publishedCampaign->title)
        ->assertDontSee($publishedNotice->title)
        ->assertDontSee($draftPost->title)
        ->set('filterCategoryId', null)
        ->set('filterStatus', PostStatus::Draft->value)
        ->assertSee($draftPost->title)
        ->assertDontSee($publishedNotice->title)
        ->assertDontSee($publishedCampaign->title);
});

it('既存のお知らせを編集して保存できる（4.8.2）', function () {
    $post = createPost();
    $newCategory = createPostCategory('campaign', 'キャンペーン');
    $admin = createAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('editPost', $post->id)
        ->set('category_id', (string) $newCategory->id)
        ->set('title', '更新後のタイトル')
        ->set('body', '更新後の本文')
        ->set('status', PostStatus::Draft->value)
        ->set('published_at', '')
        ->call('save')
        ->assertHasNoErrors();

    $post->refresh();

    expect($post->category_id)->toBe($newCategory->id)
        ->and($post->title)->toBe('更新後のタイトル')
        ->and($post->body)->toBe('更新後の本文')
        ->and($post->status)->toBe(PostStatus::Draft)
        ->and($post->published_at)->toBeNull();
});

it('gate ロールは一覧を取得できない（4.8.2 / T-12）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('公開日時が未来のお知らせは「公開予定」として一覧に表示される（4.8.2）', function () {
    $post = createPost([
        'status' => PostStatus::Published,
        'published_at' => now()->addDay(),
        'title' => '公開予定のお知らせ',
    ]);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.post.index'))
        ->assertOk()
        ->assertSee($post->title)
        ->assertSee(__('admin.post.statuses.scheduled'));
});

it('所属館が設定されていない cinema-admin は一覧を取得できない（4.7.4追記表）', function () {
    // `Post` には `CinemaScope` が付いていないため、素通しすると `forCinema(null)` が
    // 全館の記事を返す。`CinemaScope` / `Cinema::visibleTo()` と同じく403とする。
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin), 'admin')
        ->get(route('admin.post.index'))
        ->assertForbidden();
});

it('絞り込みと食い違う内容を保存すると、絞り込みが保存した内容へ寄る（4.7.4追記表）', function () {
    // 保存できたのに一覧から消える状態を作らないための寄せ（A-09 の上映日と同じ趣旨）。
    $notice = createPostCategory('notice', 'お知らせ');
    $campaign = createPostCategory('campaign', 'キャンペーン');

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->set('filterCategoryId', $notice->id)
        ->set('filterStatus', PostStatus::Draft->value)
        ->call('createPost')
        ->set(validPostForm([
            'category_id' => (string) $campaign->id,
            'status' => PostStatus::Published->value,
            'title' => 'キャンペーンのお知らせ',
        ]))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('filterCategoryId', $campaign->id)
        ->assertSet('filterStatus', '')
        ->assertSee('キャンペーンのお知らせ');
});

it('super-admin が館を未選択のときは全館のお知らせが並ぶ（4.7.4追記表）', function () {
    $one = createCinema('one-cinema', '館1');
    $another = createCinema('another-cinema', '館2');
    $onePost = createPost(['cinema_id' => $one->id, 'title' => '館1のお知らせ']);
    $anotherPost = createPost(['cinema_id' => $another->id, 'title' => '館2のお知らせ']);
    $commonPost = createPost(['cinema_id' => null, 'title' => '全館共通のお知らせ']);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->assertSee($onePost->title)
        ->assertSee($anotherPost->title)
        ->assertSee($commonPost->title);
});

it('cinema-admin は自館の記事の対象館を他館へ付け替えられない（4.7.4追記表）', function () {
    $own = createCinema('own-cinema', '自館');
    $other = createCinema('other-cinema', '他館');
    $post = createPost(['cinema_id' => $own->id]);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own), 'admin');
    $this->withoutExceptionHandling();

    $component = Livewire::test(Index::class)
        ->call('editPost', $post->id)
        ->set('cinema_id', (string) $other->id);

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect($post->refresh()->cinema_id)->toBe($own->id);
});

it('cinema-admin は自館の記事を全館共通へ付け替えられない（4.7.4追記表）', function () {
    $own = createCinema('own-cinema', '自館');
    $post = createPost(['cinema_id' => $own->id]);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own), 'admin');
    $this->withoutExceptionHandling();

    $component = Livewire::test(Index::class)
        ->call('editPost', $post->id)
        ->set('cinema_id', '');

    expect(fn () => $component->call('save'))->toThrow(AuthorizationException::class);
    expect($post->refresh()->cinema_id)->toBe($own->id);
});

it('cinema-admin は他館の記事のIDを指定しても編集画面を開けない（4.7.1 の抽出条件）', function () {
    $own = createCinema('own-cinema', '自館');
    $other = createCinema('other-cinema', '他館');
    $post = createPost(['cinema_id' => $other->id]);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own), 'admin');
    $this->withoutExceptionHandling();

    // 存在しない記事と区別せず404とする（`findVisiblePost()`）。
    expect(fn () => Livewire::test(Index::class)->call('editPost', $post->id))
        ->toThrow(NotFoundHttpException::class);
});

it('館を絞り込んだまま別の館向けに保存すると、館の絞り込みも寄る（4.7.4追記表）', function () {
    $selected = createCinema('selected-cinema', '絞り込み中の館');
    $target = createCinema('target-cinema', '保存先の館');

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->set('selectedCinemaId', $selected->id)
        ->call('createPost')
        ->set(validPostForm(['cinema_id' => (string) $target->id, 'title' => '保存先の館のお知らせ']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('selectedCinemaId', $target->id)
        ->assertSee('保存先の館のお知らせ');
});

it('館を絞り込んだまま全館共通で保存しても、館の絞り込みは変わらない（4.7.4追記表）', function () {
    $selected = createCinema('selected-cinema', '絞り込み中の館');

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->set('selectedCinemaId', $selected->id)
        ->call('createPost')
        ->set(validPostForm(['cinema_id' => '', 'title' => '全館共通のお知らせ']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('selectedCinemaId', $selected->id)
        ->assertSee('全館共通のお知らせ');
});
