<?php

use App\Enums\AdminRole;
use App\Enums\BannerPosition;
use App\Livewire\Admin\Banners\Index;
use App\Models\Banner;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * A-13（バナー）の検証。4.7.2（権限: `super-admin` のみ）と17.6（ファイル
 * アップロード）を対象とする。削除の操作は設けない（6.2）ためこの観点の検証は無い。
 */
beforeEach(function () {
    Storage::fake('public');
});

it('super-admin は画像をアップロードしてバナーを登録できる。アップロード時のファイル名は使われない（17.6-3・4）', function () {
    $admin = createAdmin();
    $file = UploadedFile::fake()->image('my-banner.jpg', 970, 250);

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm())
        ->set('image', $file)
        ->call('save')
        ->assertHasNoErrors();

    $banner = Banner::sole();

    expect($banner->image_path)->toStartWith('banners/')
        ->and($banner->image_path)->not->toContain('my-banner');

    Storage::disk('public')->assertExists($banner->image_path);
});

it('SVG画像を拒否する（17.6-2）', function () {
    $admin = createAdmin();
    $file = UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml');

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm())
        ->set('image', $file)
        ->call('save')
        ->assertHasErrors('image');

    expect(Banner::count())->toBe(0);
});

it('2MBを超える画像を拒否する（17.6-5）', function () {
    $admin = createAdmin();
    $file = UploadedFile::fake()->image('big.jpg')->size(2049);

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm())
        ->set('image', $file)
        ->call('save')
        ->assertHasErrors('image');

    expect(Banner::count())->toBe(0);
});

it('javascript: スキームのリンクURLを拒否する（17.5.2-4）', function () {
    $admin = createAdmin();
    $file = UploadedFile::fake()->image('banner.jpg');

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm(['link_url' => 'javascript:alert(1)']))
        ->set('image', $file)
        ->call('save')
        ->assertHasErrors('link_url');

    expect(Banner::count())->toBe(0);
});

it('altテキストが必須である（4.7.2-4）', function () {
    $admin = createAdmin();
    $file = UploadedFile::fake()->image('banner.jpg');

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm(['alt' => '']))
        ->set('image', $file)
        ->call('save')
        ->assertHasErrors('alt');

    expect(Banner::count())->toBe(0);
});

it('編集で画像を差し替えると新しいパスに変わり、旧ファイルが削除される', function () {
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('banners', 'public');
    $banner = createBanner(['image_path' => $oldPath]);
    $admin = createAdmin();
    $newFile = UploadedFile::fake()->image('new.jpg');

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('editBanner', $banner->id)
        ->set('image', $newFile)
        ->call('save')
        ->assertHasNoErrors();

    $banner->refresh();

    expect($banner->image_path)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($banner->image_path);
    Storage::disk('public')->assertMissing($oldPath);
});

it('編集で画像を送らなければ image_path が変わらない', function () {
    $banner = createBanner(['image_path' => 'banners/dummy.jpg']);
    $admin = createAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('editBanner', $banner->id)
        ->set('alt', '更新後のalt')
        ->call('save')
        ->assertHasNoErrors();

    expect($banner->refresh()->image_path)->toBe('banners/dummy.jpg')
        ->and($banner->alt)->toBe('更新後のalt');
});

it('掲載終了日時が開始日時より前だとエラーになる', function () {
    $admin = createAdmin();
    $file = UploadedFile::fake()->image('banner.jpg');

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm([
            'starts_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ]))
        ->set('image', $file)
        ->call('save')
        ->assertHasErrors('ends_at');

    expect(Banner::count())->toBe(0);
});

it('掲載期間・掲載状態（掲載中・開始前・期間終了）が一覧に表示される', function () {
    createBanner(['alt' => '掲載中バナー', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    createBanner(['alt' => '開始前バナー', 'starts_at' => now()->addDay()]);
    createBanner(['alt' => '期間終了バナー', 'ends_at' => now()->subDay()]);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.banner.index'))
        ->assertOk()
        ->assertSee(__('admin.banner.states.live'))
        ->assertSee(__('admin.banner.states.scheduled'))
        ->assertSee(__('admin.banner.states.ended'));
});

it('掲載位置・対象館の絞り込みが効き、対象館の絞り込みでも全館共通のバナーは残る（4.7.2）', function () {
    $cinemaA = createCinema('cinema-a', '館A');
    $cinemaB = createCinema('cinema-b', '館B');
    // alt は掲載位置ラベル等と部分一致しない固有の文字列にする（assertDontSee の
    // 誤検知を避けるため。掲載位置セレクタは絞り込みの状態に関わらず全選択肢を
    // 描くため、たとえば「メインバナー」という文字列自体は常に画面に存在する）。
    $mainBanner = createBanner(['position' => BannerPosition::Main, 'alt' => 'MAIN-ONLY-BANNER', 'cinema_id' => null]);
    $carouselA = createBanner(['position' => BannerPosition::Carousel, 'alt' => 'CAROUSEL-A-BANNER', 'cinema_id' => $cinemaA->id]);
    $carouselB = createBanner(['position' => BannerPosition::Carousel, 'alt' => 'CAROUSEL-B-BANNER', 'cinema_id' => $cinemaB->id]);
    $commonCarousel = createBanner(['position' => BannerPosition::Carousel, 'alt' => 'CAROUSEL-COMMON-BANNER', 'cinema_id' => null]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->set('filterPosition', BannerPosition::Carousel->value)
        ->assertDontSee($mainBanner->alt)
        ->assertSee($carouselA->alt)
        ->assertSee($carouselB->alt)
        ->assertSee($commonCarousel->alt)
        ->set('filterPosition', '')
        ->set('selectedCinemaId', $cinemaA->id)
        ->assertSee($carouselA->alt)
        ->assertDontSee($carouselB->alt)
        ->assertSee($commonCarousel->alt)
        ->assertSee($mainBanner->alt);
});

it('画像が実在しないバナーの行にプレースホルダーが表示される（4.7.5追記表）', function () {
    createBanner(['image_path' => 'banners/does-not-exist.jpg']);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.banner.index'))
        ->assertOk()
        ->assertSee(__('admin.banner.notices.placeholder'));
});

it('cinema-admin は一覧取得で403になる（4.7.2「権限: super-adminのみ」）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema()), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('gate ロールは一覧取得で403になる（4.7.2）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('対象館（全館共通・特定館）が保存される', function () {
    $cinema = createCinema();
    $admin = createAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm())
        ->set('image', UploadedFile::fake()->image('banner.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Banner::sole()->cinema_id)->toBeNull();

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm(['cinema_id' => (string) $cinema->id]))
        ->set('image', UploadedFile::fake()->image('banner2.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Banner::where('cinema_id', $cinema->id)->exists())->toBeTrue();
});

it('新規登録では画像が必須（17.6）', function () {
    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm())
        ->call('save')
        ->assertHasErrors('image');

    expect(Banner::count())->toBe(0);
});

it('拡張子と中身が一致しないファイルを拒否する（17.6-1）', function () {
    // 拡張子は許可の `.jpg` だが中身は `text/plain` という不整合なファイル。
    // **`mimes` と `mimetypes` のどちらが弾いたかはこのテストでは区別できない**
    // （Laravel の `mimes` はMIMEから推測した拡張子で判定するため、片方を外しても
    // 落ちる）。4.7.5追記表が両者の併記を決めた判断そのものは、テストでは固定できない。
    $file = UploadedFile::fake()->create('banner.jpg', 10, 'text/plain');

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createBanner')
        ->set(validBannerForm())
        ->set('image', $file)
        ->call('save')
        ->assertHasErrors('image');

    expect(Banner::count())->toBe(0);
});

it('BannerPolicy は cinema-admin の作成・編集を許さない（4.8.2 / 17.6-6）', function () {
    // 一覧（`viewAny`）で先に落ちるため、`save()` / `editBanner()` 経路の判定は
    // Policy を直接確かめる。
    $cinemaAdmin = createAdmin(AdminRole::CinemaAdmin, createCinema());
    $banner = createBanner();

    expect(Gate::forUser($cinemaAdmin)->allows('viewAny', Banner::class))->toBeFalse()
        ->and(Gate::forUser($cinemaAdmin)->allows('create', Banner::class))->toBeFalse()
        ->and(Gate::forUser($cinemaAdmin)->allows('updateAny', Banner::class))->toBeFalse()
        ->and(Gate::forUser($cinemaAdmin)->allows('update', $banner))->toBeFalse();

    $gateAdmin = createAdmin(AdminRole::Gate);

    expect(Gate::forUser($gateAdmin)->allows('viewAny', Banner::class))->toBeFalse()
        ->and(Gate::forUser($gateAdmin)->allows('create', Banner::class))->toBeFalse();

    $superAdmin = createAdmin();

    expect(Gate::forUser($superAdmin)->allows('create', Banner::class))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('update', $banner))->toBeTrue();
});

it('一覧は掲載位置（4.7.2 の順）→ 表示順 → ID の順に並ぶ（4.7.5追記表）', function () {
    // 掲載位置の値の昇順（carousel → footer_link → main → sub）とは異なる並びであることを固定する。
    createBanner(['position' => BannerPosition::Sub, 'alt' => 'SUB-1', 'sort_order' => 1]);
    createBanner(['position' => BannerPosition::Main, 'alt' => 'MAIN-1', 'sort_order' => 1]);
    createBanner(['position' => BannerPosition::Carousel, 'alt' => 'CAROUSEL-2', 'sort_order' => 2]);
    createBanner(['position' => BannerPosition::Carousel, 'alt' => 'CAROUSEL-1', 'sort_order' => 1]);
    createBanner(['position' => BannerPosition::FooterLink, 'alt' => 'FOOTER-1', 'sort_order' => 1]);

    $alts = Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->viewData('banners')
        ->pluck('alt')
        ->all();

    expect($alts)->toBe(['MAIN-1', 'CAROUSEL-1', 'CAROUSEL-2', 'SUB-1', 'FOOTER-1']);
});

it('一覧のリンクURLはリンクにせずテキストで出す（4.7.5追記表）', function () {
    createBanner(['link_url' => 'https://example.com/campaign', 'alt' => 'LINKED-BANNER']);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.banner.index'))
        ->assertOk()
        ->assertSee('https://example.com/campaign')
        ->assertDontSee('<a href="https://example.com/campaign"', false);
});

it('掲載期間の境界は両端を含む（4.7.5追記表）', function () {
    $now = CarbonImmutable::parse('2026-09-23 12:00:00');

    $banner = createBanner(['starts_at' => $now, 'ends_at' => $now]);

    expect($banner->isVisibleAt($now))->toBeTrue()
        ->and($banner->isVisibleAt($now->subSecond()))->toBeFalse()
        ->and($banner->isVisibleAt($now->addSecond()))->toBeFalse();

    $always = createBanner(['starts_at' => null, 'ends_at' => null]);

    expect($always->isVisibleAt($now))->toBeTrue();
});
