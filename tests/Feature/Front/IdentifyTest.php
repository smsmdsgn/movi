<?php

use App\Livewire\Front\Reservation\Identify;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/*
 * 会員／非会員の選択（P-33、7.8）。2つの導線・会員の前送り・7.17 の文言の出し分けを固定する。
 * 座席ロックの取得条件そのものは `tests/Feature/Reservation/SeatLockServiceTest.php` が担保する。
 */
it('会員・非会員の選択肢と会員特典の説明を表示する（7.8）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);
    agreeToTerms($screening);

    Livewire::test(Identify::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.identify.member.heading'))
        ->assertSee(__('front.reservation.identify.member.action'))
        ->assertSee(__('front.reservation.identify.guest.heading'))
        ->assertSee(__('front.reservation.identify.guest.action'))
        // 会員特典の説明（7.8「会員登録の動機づけ」）と、ログインで他端末の座席が
        // 解放される旨の予告（4.3.8 の宿題）。
        ->assertSee(__('front.reservation.identify.member.benefits.stamp'))
        ->assertSee(__('front.reservation.identify.member.benefits.free_ticket'))
        ->assertSee(__('front.reservation.identify.member.transfer_note'))
        // 7.8-2「会員登録して購入」は P-02 が未実装のため出さない（4.3.11、12章 残課題26）。
        // P-02 の実装時にこの行を落とし、選択肢の追加を固定すること。
        ->assertDontSee('/register');
});
it('ページが上映情報を表示しクロール対象外とする（19.3-6）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();

    // 未ログイン・座席未保持でもページ（上映情報とメタ情報）は描画される。
    // 選択肢の出し分けは Livewire コンポーネント側のテストが担保する。
    $this->get(route('front.reservation.identify', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('テスト作品')
        ->assertSee($theater->name)
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('存在しない上映回は404を返す', function () {
    $this->get(route('front.reservation.identify', ['id' => 999_999]))->assertNotFound();
});

it('ログインの導線は戻り先を保持してログイン画面へ送る（7.8-1）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);
    agreeToTerms($screening);

    Livewire::test(Identify::class, ['screening' => $screening])
        ->call('login')
        ->assertRedirect(route('login'));

    // 戻り先はサーバー側で組み立てた P-33 のURLに限る（オープンリダイレクトを作らない。4.3.11）。
    expect(session('url.intended'))
        ->toBe(route('front.reservation.identify', ['id' => $screening->id]));
});

it('非会員の導線はお客様情報の入力（P-34）へ送る（7.8-3）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);
    agreeToTerms($screening);

    Livewire::test(Identify::class, ['screening' => $screening])
        ->call('continueAsGuest')
        ->assertRedirect(route('front.reservation.customer', ['id' => $screening->id]));
});

it('ログイン済みの会員は券種選択（P-35）へ前送りする（7.18）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    $user = User::factory()->create();
    holdSeatsForScreening($screening, 'user:'.$user->id, $seats[0]);
    agreeToTerms($screening);

    Livewire::actingAs($user)
        ->test(Identify::class, ['screening' => $screening])
        ->assertRedirect(route('front.reservation.tickets', ['id' => $screening->id]));
});

it('ログイン済みでも座席を保持していなければ前送りしない（4.3.11）', function () {
    ['screening' => $screening] = makeReservationFixture();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Identify::class, ['screening' => $screening])
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.lock_expired'));
});

it('同意画面（P-32）を経ていない場合は選択肢を出さず、同意画面へ戻す導線を出す（4.3.12）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);

    // 座席は保持しているが `agreeToTerms()` を呼んでいない = URLへの直接到達。
    Livewire::test(Identify::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.agreement_required'))
        ->assertDontSee(__('front.reservation.identify.member.action'))
        ->assertDontSee(__('front.reservation.identify.guest.action'))
        // 復帰先は P-31 ではなく P-32。座席を選び直させない（4.3.12）。
        ->assertSee(route('front.reservation.agreement', ['id' => $screening->id]))
        ->call('continueAsGuest')
        ->assertNoRedirect()
        ->call('login')
        ->assertNoRedirect();
});

it('別の上映回で得た同意はこの回に持ち越さない（4.3.12）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);

    $other = createScreeningForTheater($theater);
    agreeToTerms($other);

    Livewire::test(Identify::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.agreement_required'))
        ->call('continueAsGuest')
        ->assertNoRedirect();
});

it('座席を保持していない場合は選択肢を出さず、座席選択へ戻す導線を出す（4.3.10）', function () {
    ['screening' => $screening] = makeReservationFixture();

    Livewire::test(Identify::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.lock_expired'))
        ->assertDontSee(__('front.reservation.identify.member.action'))
        ->assertDontSee(__('front.reservation.identify.guest.action'))
        ->assertSee(route('front.reservation.seats', ['id' => $screening->id]));
});

it('座席を保持していなければ導線を呼んでも進めない（4.3.11）', function () {
    ['screening' => $screening] = makeReservationFixture();

    Livewire::test(Identify::class, ['screening' => $screening])
        ->call('login')
        ->assertNoRedirect()
        ->call('continueAsGuest')
        ->assertNoRedirect();

    expect(session('url.intended'))->toBeNull();
});

it('別の上映回の座席を保持している場合は期限切れではなく専用の案内を出す（4.3.10）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();

    $other = createScreeningForTheater($theater);
    $other->update([
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(15, 0),
        'ends_at' => CarbonImmutable::now()->addDay()->setTime(17, 0),
    ]);
    holdSeatsForScreening($other, null, $seats[0]);

    Livewire::test(Identify::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.other_screening_reselect'))
        ->assertDontSee(__('front.reservation.errors.lock_expired'));
});

it('販売期間外の上映回では選択肢を出さず、7.17 の文言のみを表示する（4.3.1）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);
    agreeToTerms($screening);

    $screening->update([
        'starts_at' => CarbonImmutable::now()->subHour(),
        'ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    Livewire::test(Identify::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.out_of_sale'))
        ->assertDontSee(__('front.reservation.identify.member.action'))
        ->call('continueAsGuest')
        ->assertNoRedirect();
});

it('上映回が削除されても 7.17 の文言を返し、例外にしない（4.3.10）', function () {
    ['screening' => $screening] = makeReservationFixture();

    $component = Livewire::test(Identify::class, ['screening' => $screening]);

    $screening->delete();

    $component->call('continueAsGuest')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.out_of_sale'));
});

it('ログイン済みの会員は HTTP のフルページでも券種選択へ前送りされる（4.3.11）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    $user = User::factory()->create();
    holdSeatsForScreening($screening, 'user:'.$user->id, $seats[0]);

    // Livewire::test() は mount() の redirect を直接観測するだけで、フルページの経路
    // （初回描画の dehydrate による abort(redirect())）を通らないため、実経路を固定する。
    $this->actingAs($user)
        ->withSession(agreedDraftSession($screening))
        ->get(route('front.reservation.identify', ['id' => $screening->id]))
        ->assertRedirect(route('front.reservation.tickets', ['id' => $screening->id]));
});

it('ログイン後は保持した戻り先（P-33）へ復帰する（7.8 / 4.3.11）', function () {
    ['screening' => $screening] = makeReservationFixture();
    $user = User::factory()->create(['password' => Hash::make('password-for-test')]);
    $intended = route('front.reservation.identify', ['id' => $screening->id]);

    // P-33 の login() が入れる値と同じものを与え、Fortify の LoginResponse が
    // redirect()->intended() で拾うことを確認する。
    $this->withSession(['url.intended' => $intended])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password-for-test',
        ])
        ->assertRedirect($intended);

    $this->assertAuthenticatedAs($user);

    // 戻り先は消費され、以後のログインに持ち越されない（12章 残課題27 の前提）。
    expect(session('url.intended'))->toBeNull();
});

it('Fortify は セッションIDの再生成より前に Login を発火する（13.4.6 の前提）', function () {
    $user = User::factory()->create(['password' => Hash::make('password-for-test')]);

    // `TransferSeatLocksOnLogin` は「リスナーの時点では移譲元の `session:{id}` を読める」
    // ことに依拠している（13.4.6）。ロックの移譲そのものは
    // tests/Feature/Auth/SeatLockTransferTest.php が担保するが、**順序の前提は
    // そこでは検証できない**（`event()` の直接発火のためパイプラインを通らない）。
    // Fortify の実行順が変わったら気づけるよう、ここで固定する。
    $idAtLogin = null;
    Event::listen(Login::class, function () use (&$idAtLogin): void {
        $idAtLogin = Session::getId();
    });

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password-for-test',
    ]);

    $this->assertAuthenticatedAs($user);

    expect($idAtLogin)->not->toBeNull();
    // Login の後にセッションIDが再生成されている＝リスナーは再生成前の値を読めている。
    expect(Session::getId())->not->toBe($idAtLogin);
});
