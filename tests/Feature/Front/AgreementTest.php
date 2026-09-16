<?php

use App\Livewire\Front\Reservation\Agreement;
use App\Models\SeatLock;
use App\Models\User;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * 同意画面（P-32、7.7 / 4.3.7）。予約内容の表示・利用規約への同意・次へ進む条件を固定する。
 * 座席ロックの取得条件そのものは `tests/Feature/Reservation/SeatLockServiceTest.php` が担保する。
 */
it('同意画面が上映情報・選択中の座席・利用規約の要約を表示し、クロール対象外とする（4.3.7 / 19.3-6）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();

    // HTTPリクエストは別のセッションIDを持つため、保持者キーの定まる会員として検証する（13.3）。
    $user = User::factory()->create();
    holdSeatsForScreening($screening, 'user:'.$user->id, $seats[0]);

    $this->actingAs($user)
        ->get(route('front.reservation.agreement', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('テスト作品')
        ->assertSee($theater->name)
        ->assertSee($theater->cinema->name)
        ->assertSee($seats[0]->displayName())
        ->assertSee(__('front.reservation.agreement.terms.no_change'))
        ->assertSee(__('front.reservation.agreement.terms.cancel_deadline'))
        ->assertSee(__('front.reservation.agreement.terms.late_entry'))
        ->assertSee(route('front.terms.index'))
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('存在しない上映回は404を返す', function () {
    $this->get(route('front.reservation.agreement', ['id' => 999_999]))->assertNotFound();
});

it('上映回の館をヘッダー・パンくずの館として確定させる（4.1.3-1 / 4.3.9）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();
    createCinema('other-cinema', '別の館');

    $this->withSession(['cinema_slug' => 'other-cinema'])
        ->get(route('front.reservation.agreement', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">'.$theater->cinema->name.'<', escape: false)
        ->assertSessionHas('cinema_slug', $theater->cinema->slug);
});

it('座席を保持していない場合は同意を求めず、座席選択へ戻す導線を出す（4.3.10）', function () {
    ['screening' => $screening] = makeReservationFixture();

    Livewire::test(Agreement::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.lock_expired'))
        ->assertDontSee(__('front.reservation.agreement.agree'))
        ->assertSee(route('front.reservation.seats', ['id' => $screening->id]));
});

it('同意画面はポーリングしない（4.3.10）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);

    Livewire::test(Agreement::class, ['screening' => $screening])
        ->assertDontSee('wire:poll', escape: false);
});

it('別の上映回の座席を保持している場合は期限切れではなく専用の案内を出す（4.3.10 / 7.17）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();

    // 回Aの座席を保持したまま、回Bの同意画面のURLへ直接到達した状態。
    $other = createScreeningForTheater($theater);
    $other->update([
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(15, 0),
        'ends_at' => CarbonImmutable::now()->addDay()->setTime(17, 0),
    ]);
    holdSeatsForScreening($other, null, $seats[0]);

    Livewire::test(Agreement::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.other_screening_reselect'))
        ->assertDontSee(__('front.reservation.errors.lock_expired'))
        ->set('agreed', true)
        ->call('proceed')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.other_screening_reselect'));
});

it('保持中の座席が期限切れになると、次へ進めず座席の選択からやり直しを案内する（6.4.1-3 / 7.17）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);

    $component = Livewire::test(Agreement::class, ['screening' => $screening])->set('agreed', true);

    $this->travel(SeatLockService::LOCK_MINUTES + 1)->minutes();

    $component->call('proceed')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.lock_expired'));
});

it('同意しないまま次へ進むことはできない（4.3.7-7）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);

    Livewire::test(Agreement::class, ['screening' => $screening])
        ->call('proceed')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.not_agreed'));
});

it('同意して次へ進むと会員／非会員の選択（P-33）へ遷移する（7.18）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0], $seats[1]);

    Livewire::test(Agreement::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.selected.count', ['count' => 2]))
        ->set('agreed', true)
        ->call('proceed')
        ->assertRedirect(route('front.reservation.identify', ['id' => $screening->id]));
});

it('販売期間外の上映回では同意を求めず、7.17 の文言のみを表示する（4.3.1）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);

    $screening->update([
        'starts_at' => CarbonImmutable::now()->subHour(),
        'ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    Livewire::test(Agreement::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.out_of_sale'))
        ->assertDontSee(__('front.reservation.agreement.agree'))
        ->set('agreed', true)
        ->call('proceed')
        ->assertNoRedirect();
});

it('上映回が削除されても 7.17 の文言を返し、例外にしない（4.3.10）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);

    $component = Livewire::test(Agreement::class, ['screening' => $screening])->set('agreed', true);

    // A-09 は有効な座席ロックがある回を削除しないが、画面を開いたまま期限が切れれば削除できる。
    SeatLock::query()->delete();
    $screening->delete();

    $component->call('proceed')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.out_of_sale'));
});
