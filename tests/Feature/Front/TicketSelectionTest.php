<?php

use App\Enums\Discount;
use App\Livewire\Front\Reservation\TicketSelection;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\Theater;
use App\Models\User;
use App\Services\ReservationDraft;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * 券種選択（P-35、7.10）。券種の割り当て・金額の表示・到達の前提を固定する。
 * 金額の計算そのものは tests/Feature/Reservation/PricingServiceTest.php が担保する。
 */

/**
 * 先へ進める前提（座席の保持・利用規約への同意・非会員のお客様情報）を満たした
 * 上映回を用意する（4.3.14）。
 *
 * @return array{screening: Screening, seats: list<Seat>, theater: Theater}
 */
function readyForTicketSelection(int $seatCount = 2): array
{
    $fixture = makeReservationFixture($seatCount);
    holdSeatsForScreening($fixture['screening'], null, ...$fixture['seats']);
    agreeToTerms($fixture['screening']);
    enterGuestInfo($fixture['screening']);

    return $fixture;
}

it('保持中の座席と券種の選択肢を表示する（7.10-1 / 7.10-2）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);
    $student = studentTicket(1500);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSee($seats[0]->displayName())
        ->assertSee($seats[1]->displayName())
        ->assertSee(__('front.reservation.tickets.option', ['name' => $adult->name, 'price' => '2,000']))
        ->assertSee(__('front.reservation.tickets.option', ['name' => $student->name, 'price' => '1,500']))
        ->assertSee(__('front.reservation.tickets.unselected'));
});

it('ページが上映情報を表示しクロール対象外とする（19.3-6）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();

    $this->get(route('front.reservation.tickets', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('テスト作品')
        ->assertSee($theater->name)
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('存在しない上映回は404を返す', function () {
    $this->get(route('front.reservation.tickets', ['id' => 999_999]))->assertNotFound();
});

it('券種を選ぶまで金額を表示しない（4.3.13）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    $component = Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.tickets.amount_pending'));

    // 1席だけ選んだ時点でも出さない。一部の席で計算した小計は、ペア割の成否が
    // 選択の途中で変わって見える。
    $component->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->assertSee(__('front.reservation.tickets.amount_pending'));

    $component->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->assertDontSee(__('front.reservation.tickets.amount_pending'))
        ->assertSee(__('front.reservation.tickets.total'));
});

it('小計・割引額・支払金額を表示する（7.10-3 / 7.10-5）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    // 大人2枚なのでペア割が成立する（6.5.2）。
    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->assertSee(__('front.reservation.yen', ['amount' => '4,000']))
        ->assertSee(Discount::Pair->label())
        ->assertSee(__('front.reservation.yen', ['amount' => '3,000']));
});

it('券種を選んでいない座席があると次へ進めない（7.10）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->call('submit')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.ticket_type_required'));

    expect(app(ReservationDraft::class)->tickets($screening->id))->toBe([]);
});

it('すべての券種を選ぶと決済（P-36）へ進み、割り当てを持ち越す（7.18 / 4.3.13）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);
    $student = studentTicket(1500);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $student->id)
        ->call('submit')
        ->assertRedirect(route('front.reservation.payment', ['id' => $screening->id]));

    expect(app(ReservationDraft::class)->tickets($screening->id))->toBe([
        $seats[0]->id => $adult->id,
        $seats[1]->id => $student->id,
    ]);
});

it('存在しない券種IDを送っても保存しない（17章）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, '999999')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.ticket_type_required'));

    expect(app(ReservationDraft::class)->tickets($screening->id))->toBe([]);
});

it('非数値の券種IDを送っても例外にしない', function (string $value) {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, $value)
        ->call('submit')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.ticket_type_required'));
})->with([
    '空文字' => [''],
    '文字列' => ['adult'],
    '小数' => ['1.5'],
]);

it('保持していない座席に券種を割り当てても保存しない（17章）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection(seatCount: 3);
    $adult = adultTicket(2000);
    $locks = app(SeatLockService::class);

    // 3席目のロックを解放する。画面上は2席になるが、クライアントは3席分を送ってくる。
    $locks->release($screening, $seats[2], $locks->holderKey());

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->set('selections.'.$seats[2]->id, (string) $adult->id)
        ->call('submit')
        ->assertRedirect(route('front.reservation.payment', ['id' => $screening->id]));

    // 保持している2席の分だけが残る。
    expect(app(ReservationDraft::class)->tickets($screening->id))->toBe([
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);
});

it('券種を確定し直すと、用意済みの支払方法を捨てる（4.3.14）', function () {
    // P-36 でカードを用意した後に券種を変えると支払金額が変わる。前の内容のために
    // 用意した PaymentMethod を持ち越さない。
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);
    $student = studentTicket(1500);
    app(ReservationDraft::class)->putPaymentMethod($screening, 'pm_card_visa');

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $student->id)
        ->call('submit')
        ->assertRedirect(route('front.reservation.payment', ['id' => $screening->id]));

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('記録済みの割り当てを書き戻す（P-36 から戻った場合）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);
    $student = studentTicket(1500);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $student->id)
        ->call('submit');

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSet('selections.'.$seats[0]->id, (string) $adult->id)
        ->assertSet('selections.'.$seats[1]->id, (string) $student->id);
});

it('座席を選び直した場合、保持していない座席の割り当ては書き戻さない（4.3.13）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection(seatCount: 3);
    $adult = adultTicket(2000);
    $locks = app(SeatLockService::class);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->set('selections.'.$seats[2]->id, (string) $adult->id)
        ->call('submit')
        ->assertRedirect(route('front.reservation.payment', ['id' => $screening->id]));

    $locks->release($screening, $seats[2], $locks->holderKey());

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSet('selections.'.$seats[0]->id, (string) $adult->id)
        ->assertSet('selections.'.$seats[2]->id, null);
});

it('同意画面（P-32）を経ていない場合は選択を求めず、同意画面へ戻す導線を出す（4.3.12）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);
    $adult = adultTicket(2000);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.agreement_required'))
        ->assertDontSee(__('front.reservation.tickets.proceed'))
        ->assertSee(route('front.reservation.agreement', ['id' => $screening->id]))
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->call('submit')
        ->assertNoRedirect();

    expect(app(ReservationDraft::class)->tickets($screening->id))->toBe([]);
});

it('座席を保持していない場合は選択を求めず、座席選択へ戻す導線を出す（4.3.12）', function () {
    ['screening' => $screening] = makeReservationFixture();
    agreeToTerms($screening);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.lock_expired'))
        ->assertDontSee(__('front.reservation.tickets.proceed'))
        ->assertSee(route('front.reservation.seats', ['id' => $screening->id]))
        ->call('submit')
        ->assertNoRedirect();
});

it('非会員がお客様情報（P-34）を入力していない場合は選択を求めず、入力画面へ戻す（4.3.14）', function () {
    // P-32 の後に P-35 のURLへ直接到達した非会員。連絡先を持たないまま決済へ進める
    // 経路を塞ぐ（旧12章 残課題25-a）。
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, ...$seats);
    agreeToTerms($screening);
    $adult = adultTicket(2000);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.customer_info_required'))
        ->assertDontSee(__('front.reservation.tickets.proceed'))
        ->assertSee(route('front.reservation.customer', ['id' => $screening->id]))
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->call('submit')
        ->assertNoRedirect();

    expect(app(ReservationDraft::class)->tickets($screening->id))->toBe([]);
});

it('保持中の座席が期限切れになると確定できない（6.4.1-3）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    $component = Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $adult->id);

    $this->travel(SeatLockService::LOCK_MINUTES + 1)->minutes();

    $component->call('submit')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.lock_expired'));

    expect(app(ReservationDraft::class)->tickets($screening->id))->toBe([]);
});

it('販売期間外の上映回では選択を求めず、復帰先も出さない（4.3.1 / 4.3.12）', function () {
    ['screening' => $screening] = readyForTicketSelection();

    $screening->update([
        'starts_at' => CarbonImmutable::now()->subHour(),
        'ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.out_of_sale'))
        ->assertDontSee(__('front.reservation.tickets.proceed'))
        ->assertDontSee(__('front.reservation.back_to_seats'))
        ->call('submit')
        ->assertNoRedirect();
});

it('上映回が削除されても 7.17 の文言を返し、例外にしない（4.3.10）', function () {
    ['screening' => $screening] = readyForTicketSelection();

    $component = Livewire::test(TicketSelection::class, ['screening' => $screening]);

    $screening->delete();

    $component->call('submit')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.out_of_sale'));
});

it('会員も非会員も同じ画面に合流する（7.18）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    $user = User::factory()->create();
    holdSeatsForScreening($screening, 'user:'.$user->id, ...$seats);
    agreeToTerms($screening);
    $adult = adultTicket(2000);

    Livewire::actingAs($user)
        ->test(TicketSelection::class, ['screening' => $screening])
        ->assertNoRedirect()
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->set('selections.'.$seats[2]->id, (string) $adult->id)
        ->call('submit')
        ->assertRedirect(route('front.reservation.payment', ['id' => $screening->id]));
});

it('別の上映回には割り当てを引き継がない（4.3.13）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = readyForTicketSelection();
    $adult = adultTicket(2000);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->call('submit');

    $other = createScreeningForTheater($theater);

    expect(app(ReservationDraft::class)->tickets($other->id))->toBe([]);
});

it('戻る導線は座席選択（P-31）を指す。P-33 は前送りするため指さない（4.3.13）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->assertSee(route('front.reservation.seats', ['id' => $screening->id]))
        // 会員は P-33 の mount() で P-35 へ前送りされるため、押しても跳ね返る。
        ->assertDontSee(route('front.reservation.identify', ['id' => $screening->id]));
});

it('未選択を指摘した後、残りを選ぶと案内が消える（4.3.10）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    $component = Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->call('submit')
        ->assertSee(__('front.reservation.errors.ticket_type_required'));

    // セレクトは wire:model.live のため送信の合間に再描画が走る。金額が出ている隣に
    // 「券種をお選びください」を残さない。
    $component->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->assertDontSee(__('front.reservation.errors.ticket_type_required'))
        ->assertSee(__('front.reservation.tickets.total'));
});

it('券種の適用条件を表示する（4.8.6追記表）', function () {
    ['screening' => $screening] = readyForTicketSelection();
    adultTicket(2000);
    $student = studentTicket(1500);
    $student->update(['condition' => '大学・専門学校。要学生証']);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.tickets.conditions_heading'))
        ->assertSee('大学・専門学校。要学生証');
});

it('boolean を券種IDとして送っても券種ID 1 として受理しない（17章）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    // `filter_var(true, FILTER_VALIDATE_INT)` は 1 を返す。型を先に確かめていないと
    // 「利用者が選んでいない券種」を受理しうる。
    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, true)
        ->call('submit')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.ticket_type_required'));

    expect(app(ReservationDraft::class)->tickets($screening->id))->toBe([]);
});

it('保持座席以外のキーは確定時に落とす', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForTicketSelection();
    $adult = adultTicket(2000);

    Livewire::test(TicketSelection::class, ['screening' => $screening])
        ->set('selections.'.$seats[0]->id, (string) $adult->id)
        ->set('selections.'.$seats[1]->id, (string) $adult->id)
        ->set('selections.999999', (string) $adult->id)
        ->call('submit')
        ->assertRedirect(route('front.reservation.payment', ['id' => $screening->id]))
        // スナップショットが際限なく膨らまないよう、保持座席のぶんだけを残す。
        ->assertSet('selections.999999', null);
});
