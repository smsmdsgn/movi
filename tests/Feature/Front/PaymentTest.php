<?php

use App\Livewire\Front\Reservation\Payment;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Theater;
use App\Models\User;
use App\Services\ReservationDraft;
use App\Services\SeatLockService;
use App\Services\StripeException;
use App\Services\StripeService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * 決済（P-36、7.11）。支払方法の選択・カードの確認・到達の前提を固定する。
 *
 * **Stripe とは通信しない。** `StripeService` を差し替える（サービス自身の振る舞いは
 * tests/Feature/Reservation/StripeServiceTest.php が担保する）。
 * 金額の計算そのものは tests/Feature/Reservation/PricingServiceTest.php が担保する。
 */

/**
 * 先へ進める前提（座席・同意・お客様情報・券種）を満たした上映回を用意する（4.3.14）。
 *
 * @return array{screening: Screening, seats: list<Seat>, theater: Theater}
 */
function readyForPayment(int $seatCount = 2, int $price = 2000): array
{
    $fixture = makeReservationFixture($seatCount);
    holdSeatsForScreening($fixture['screening'], null, ...$fixture['seats']);
    agreeToTerms($fixture['screening']);
    enterGuestInfo($fixture['screening']);
    assignTickets($fixture['screening'], adultTicket($price), ...$fixture['seats']);

    return $fixture;
}

/**
 * Stripe を差し替える。`$usable` が null の場合は `StripeException` を送出する。
 */
function fakeStripe(bool $configured = true, ?bool $usable = true, string $expectedId = 'pm_test_card'): void
{
    $stripe = Mockery::mock(StripeService::class);
    $stripe->shouldReceive('isConfigured')->andReturn($configured);
    $stripe->shouldReceive('publishableKey')->andReturn($configured ? 'pk_test_dummy' : '');

    // 画面が受け取ったIDをそのままサービスへ渡していることも固定する。
    if ($usable === null) {
        $stripe->shouldReceive('isUsableCard')->with($expectedId)->andThrow(StripeException::requestFailed());
    } else {
        $stripe->shouldReceive('isUsableCard')->with($expectedId)->andReturn($usable);
    }

    app()->instance(StripeService::class, $stripe);
}

it('支払方法とテストカードの案内、支払金額を表示する（7.11.1 / 7.11.2）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe();

    Livewire::test(Payment::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.payment.methods.card'))
        ->assertSee(__('front.reservation.payment.methods.hoge_pay'))
        ->assertSee(__('front.reservation.payment.methods.fuga_pay'))
        ->assertSee(__('front.reservation.payment.methods.mogo_pay'))
        // 7.11.1 クレジットカード以外を選んだ場合の案内。
        ->assertSee(__('front.reservation.payment.card_only'))
        // 7.11.2 デモであること・課金が発生しないこと。
        ->assertSee(__('front.reservation.payment.test_cards.note'))
        ->assertSee('4242 4242 4242 4242')
        // 大人2,000円×2席（ペア割で1,500円×2席）。
        ->assertSee(__('front.reservation.yen', ['amount' => '3,000']))
        // 戻り先は直前の P-35（4.3.14）。
        ->assertSee(__('front.reservation.back_to_tickets'))
        ->assertSee(route('front.reservation.tickets', ['id' => $screening->id]))
        // Livewire の再描画で Elements の iframe ごと差し替えられると、入力中の
        // カード情報が消える（4.3.14）。
        ->assertSee('wire:ignore', escape: false);
});

it('ページが上映情報と Stripe.js の読み込みを含み、クロール対象外となる（8.2 / 19.3-6）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();

    $this->get(route('front.reservation.payment', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('テスト作品')
        ->assertSee($theater->name)
        // カード情報が自サイトのコードを通らないよう、Stripe の配信元から読み込む（17.3-1）。
        ->assertSee('https://js.stripe.com/v3/', escape: false)
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('存在しない上映回は404を返す', function () {
    $this->get(route('front.reservation.payment', ['id' => 999_999]))->assertNotFound();
});

it('決済画面への遷移で座席ロックを15分へ延長する（6.4.1-4 / 4.3.8）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe();

    // P-31 で取得した時点の期限は10分。
    expect(SeatLock::where('screening_id', $screening->id)->first()?->expires_at)
        ->toBeLessThanOrEqual(CarbonImmutable::now()->addMinutes(SeatLockService::LOCK_MINUTES));

    Livewire::test(Payment::class, ['screening' => $screening]);

    $expiresAt = SeatLock::where('screening_id', $screening->id)->first()?->expires_at;

    expect($expiresAt)->toBeGreaterThan(CarbonImmutable::now()->addMinutes(SeatLockService::LOCK_MINUTES))
        ->and($expiresAt)->toBeLessThanOrEqual(CarbonImmutable::now()->addMinutes(SeatLockService::PAYMENT_LOCK_MINUTES));
});

it('支払金額が0円の場合は決済を通さず予約確認へ送る（4.5.2）', function () {
    // 割引のみ・券種価格のみのいずれで0円になっても同じ扱いとする（旧12章 残課題29）。
    ['screening' => $screening] = readyForPayment(price: 0);
    fakeStripe();

    Livewire::test(Payment::class, ['screening' => $screening])
        ->assertRedirect(route('front.reservation.confirm', ['id' => $screening->id]));
});

it('カードを確認できたら予約確認へ進み、PaymentMethod のIDを持ち越す（7.18 / 17.3-3）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe();

    Livewire::test(Payment::class, ['screening' => $screening])
        ->call('preparePayment', 'pm_test_card')
        ->assertRedirect(route('front.reservation.confirm', ['id' => $screening->id]));

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBe('pm_test_card');
});

it('Stripe が認めないカードは決済失敗として画面に留まる（7.17）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe(usable: false, expectedId: 'pm_unknown');

    Livewire::test(Payment::class, ['screening' => $screening])
        ->call('preparePayment', 'pm_unknown')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.payment_failed'));

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('Stripe との通信に失敗しても例外の内容を画面に出さない（17.9-1）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe(usable: null);

    Livewire::test(Payment::class, ['screening' => $screening])
        ->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.payment_failed'))
        // 例外のメッセージはリクエストの内容を含みうるため、画面には出さない。
        ->assertDontSee('The request to Stripe failed.');

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('文字列以外のIDを送っても例外にしない（17章）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe();

    Livewire::test(Payment::class, ['screening' => $screening])
        ->call('preparePayment', ['pm_test_card'])
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.payment_failed'));

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('券種（P-35）が揃っていない場合はカードを受け付けず、券種選択へ戻す（4.3.14）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, ...$seats);
    agreeToTerms($screening);
    enterGuestInfo($screening);
    fakeStripe();

    Livewire::test(Payment::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.ticket_selection_required'))
        ->assertSee(route('front.reservation.tickets', ['id' => $screening->id]))
        ->assertDontSee(__('front.reservation.payment.submit'))
        ->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect();

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('座席を選び直して割り当てが欠けた場合も券種選択へ戻す（4.3.13）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture(3);
    holdSeatsForScreening($screening, null, ...$seats);
    agreeToTerms($screening);
    enterGuestInfo($screening);
    fakeStripe();

    // 3席を保持しているが、割り当てがあるのは2席分。支払金額が定まらない。
    assignTickets($screening, adultTicket(2000), $seats[0], $seats[1]);

    Livewire::test(Payment::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.ticket_selection_required'))
        ->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect();
});

it('割り当てた券種が券種マスタから消えた場合も券種選択へ戻す（例外にしない）', function () {
    // `PricingService::calculate()` は存在しない券種IDに例外を投げる（13.4.5）。
    // 4.3.10 の方針どおり、500ではなく 7.17 の文言とやり直しの導線を出す。
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture(2);
    holdSeatsForScreening($screening, null, ...$seats);
    agreeToTerms($screening);
    enterGuestInfo($screening);
    $adult = adultTicket(2000);
    assignTickets($screening, $adult, ...$seats);
    fakeStripe();

    $adult->delete();

    Livewire::test(Payment::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.ticket_selection_required'))
        ->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect();

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('非会員がお客様情報（P-34）を入力していない場合は入力画面へ戻す（4.3.14）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, ...$seats);
    agreeToTerms($screening);
    assignTickets($screening, adultTicket(2000), ...$seats);
    fakeStripe();

    Livewire::test(Payment::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.customer_info_required'))
        ->assertSee(route('front.reservation.customer', ['id' => $screening->id]))
        ->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect();

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('会員はお客様情報の入力を前提としない（7.8 / 4.3.14）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    $user = User::factory()->create();
    holdSeatsForScreening($screening, 'user:'.$user->id, ...$seats);
    agreeToTerms($screening);
    assignTickets($screening, adultTicket(2000), ...$seats);
    fakeStripe();

    Livewire::actingAs($user)
        ->test(Payment::class, ['screening' => $screening])
        ->assertDontSee(__('front.reservation.errors.customer_info_required'))
        ->assertSee(__('front.reservation.payment.submit'));
});

it('保持中の座席が期限切れになるとカードを受け付けない（6.4.1-3）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe();

    $component = Livewire::test(Payment::class, ['screening' => $screening]);

    $this->travel(SeatLockService::PAYMENT_LOCK_MINUTES + 1)->minutes();

    $component->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.lock_expired'));

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('Stripe のキーが未設定の場合はカードの入力欄を出さず案内を表示する（15.1）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe(configured: false);

    Livewire::test(Payment::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.payment.errors.unavailable'))
        ->assertDontSee(__('front.reservation.payment.card_heading'))
        ->assertDontSee(__('front.reservation.payment.submit'))
        // 金額は前提が揃っていれば表示する（決済だけが行えない状態のため）。
        ->assertSee(__('front.reservation.payment.amount_heading'))
        ->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.payment.errors.unavailable'));

    expect(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('販売期間外の上映回では入力を求めず、復帰先も出さない（4.3.1 / 4.3.12）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe();

    $screening->update([
        'starts_at' => CarbonImmutable::now()->subHour(),
        'ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    Livewire::test(Payment::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.out_of_sale'))
        ->assertDontSee(__('front.reservation.payment.submit'))
        ->assertDontSee(__('front.reservation.back_to_tickets'))
        ->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect();
});

it('上映回が削除されても 7.17 の文言を返し、例外にしない（4.3.10）', function () {
    ['screening' => $screening] = readyForPayment();
    fakeStripe();

    $component = Livewire::test(Payment::class, ['screening' => $screening]);

    $screening->delete();

    $component->call('preparePayment', 'pm_test_card')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.out_of_sale'));
});
