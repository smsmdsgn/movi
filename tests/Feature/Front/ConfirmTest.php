<?php

use App\Enums\ReservationStatus;
use App\Livewire\Front\Reservation\Confirm;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Theater;
use App\Models\User;
use App\Services\CardCharge;
use App\Services\PaymentAttempt;
use App\Services\PriceBreakdown;
use App\Services\Purchaser;
use App\Services\ReservationDraft;
use App\Services\ReservationService;
use App\Services\SeatLockService;
use App\Services\StripeException;
use App\Services\StripeService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * 予約確認（P-37、7.12）。表示項目・到達の前提・確定の操作を固定する。
 *
 * 課金と確定トランザクションそのものは
 * tests/Feature/Reservation/ReservationServiceTest.php が担保する。
 */

/**
 * 先へ進める前提（座席・同意・お客様情報・券種・支払方法）を満たした上映回を用意する。
 *
 * @return array{screening: Screening, seats: list<Seat>, theater: Theater}
 */
function readyForConfirm(int $seatCount = 2, int $price = 2000, bool $withPaymentMethod = true): array
{
    $fixture = makeReservationFixture($seatCount);
    holdSeatsForScreening($fixture['screening'], null, ...$fixture['seats']);
    agreeToTerms($fixture['screening']);
    enterGuestInfo($fixture['screening']);
    assignTickets($fixture['screening'], adultTicket($price), ...$fixture['seats']);

    if ($withPaymentMethod) {
        app(ReservationDraft::class)->putPaymentMethod($fixture['screening'], 'pm_card_visa');
    }

    return $fixture;
}

it('座席と券種・金額の内訳・お客様情報・確保期限・確定ボタンを表示する（7.12）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForConfirm();
    fakeStripeService(settledCharge(3000));

    Livewire::test(Confirm::class, ['screening' => $screening])
        // 7.12-2 座席と券種
        ->assertSee($seats[0]->displayName())
        ->assertSee($seats[1]->displayName())
        ->assertSee('大人')
        // 7.12-3 支払金額の内訳（大人2,000円×2席。ペア割で3,000円）
        ->assertSee(__('front.reservation.tickets.subtotal'))
        ->assertSee(__('front.reservation.yen', ['amount' => '4,000']))
        ->assertSee(__('front.reservation.yen', ['amount' => '3,000']))
        // 7.12-4 購入者情報
        ->assertSee('祇園　太郎')
        ->assertSee('guest@example.test')
        // 7.12-5 座席ロックの残り時間
        ->assertSee(__('front.reservation.confirm.hold_heading'))
        // 7.12-6 確定ボタン
        ->assertSee(__('front.reservation.confirm.submit'));
});

it('ページが上映情報と Stripe.js の読み込みを含み、クロール対象外となる（19.3-6）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();

    $this->get(route('front.reservation.confirm', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('テスト作品')
        ->assertSee($theater->name)
        // 追加認証（3Dセキュア）を `handleNextAction()` で処理するため必要（4.3.15）。
        ->assertSee('https://js.stripe.com/v3/', escape: false)
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('存在しない上映回は404を返す', function () {
    $this->get(route('front.reservation.confirm', ['id' => 999_999]))->assertNotFound();
});

it('確定すると予約完了（P-38）へ進み、下書きを破棄する（7.18）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForConfirm();
    $stripe = fakeStripeService(settledCharge(3000));

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->call('confirm')
        ->assertRedirectContains('/complete');

    $reservation = Reservation::firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Paid)
        ->and($reservation->guest_email)->toBe('guest@example.test')
        ->and(ReservationSeat::where('reservation_id', $reservation->id)->count())->toBe(count($seats))
        ->and($stripe->charges[0]['paymentMethodId'])->toBe('pm_card_visa')
        // 確定後に同じ内容でもう一度確定できないよう、持ち越しを捨てる。
        ->and(app(ReservationDraft::class)->tickets($screening->id))->toBe([])
        ->and(app(ReservationDraft::class)->paymentMethodId($screening->id))->toBeNull();
});

it('支払方法が用意されていない場合は決済画面へ戻す（4.3.15）', function () {
    ['screening' => $screening] = readyForConfirm(withPaymentMethod: false);
    fakeStripeService(settledCharge(3000));

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.payment_method_required'))
        ->assertSee(route('front.reservation.payment', ['id' => $screening->id]))
        ->assertDontSee(__('front.reservation.confirm.submit'))
        ->call('confirm')
        ->assertNoRedirect();

    expect(Reservation::count())->toBe(0);
});

it('支払金額が0円なら支払方法が無くても確定できる（4.5.2）', function () {
    ['screening' => $screening] = readyForConfirm(price: 0, withPaymentMethod: false);
    $stripe = fakeStripeService(settledCharge(0));

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.confirm.no_payment_note'))
        ->call('confirm')
        ->assertRedirectContains('/complete');

    expect(Reservation::firstOrFail()->status)->toBe(ReservationStatus::Paid)
        ->and($stripe->charges)->toBe([]);
});

it('追加認証が必要な場合は確定せず、ブラウザへ認証を求める（3Dセキュア）', function () {
    ['screening' => $screening] = readyForConfirm();
    fakeStripeService(CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, 3000, 'pi_3ds_secret'));

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->call('confirm')
        ->assertNoRedirect()
        ->assertDispatched('payment-authentication-required', clientSecret: 'pi_3ds_secret')
        ->assertSet('awaitingAuthentication', true);

    expect(Reservation::firstOrFail()->status)->toBe(ReservationStatus::Pending)
        ->and(ReservationSeat::count())->toBe(0);
});

it('認証の完了通知を受けたらサーバーが再検証して確定する（17.3-3）', function () {
    ['screening' => $screening] = readyForConfirm();
    fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, 3000, 'pi_3ds_secret'),
        settledCharge(3000, 'pi_3ds'),
    );

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->call('confirm')
        ->call('completeAuthentication')
        ->assertRedirectContains('/complete');

    expect(Reservation::firstOrFail()->status)->toBe(ReservationStatus::Paid);
});

it('認証が完了していない場合は決済失敗として画面に留まる（7.17）', function () {
    ['screening' => $screening] = readyForConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, 3000, 'pi_3ds_secret'),
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, 3000, 'pi_3ds_secret'),
    );

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->call('confirm')
        ->call('completeAuthentication')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.payment_failed'))
        ->assertSet('awaitingAuthentication', false);

    expect($stripe->cancellations)->toBe(['pi_3ds']);
});

it('カードが拒否された場合は画面に留まり、再試行できる（8.2「決済失敗時の扱い」）', function () {
    ['screening' => $screening] = readyForConfirm();
    fakeStripeService(CardCharge::declined('pi_declined'));

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->call('confirm')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.payment_failed'))
        // 決済のやり直しができるよう確定ボタンを残す。
        ->assertSee(__('front.reservation.confirm.submit'))
        // カードを変える導線も出す。
        ->assertSee(route('front.reservation.payment', ['id' => $screening->id]))
        // 同じ予約で再試行すると冪等キーが同じになるため、次は作り直す。
        ->assertSet('pendingReservationId', null);
});

it('課金後に座席を失った場合は返金の案内と座席選択への導線を出す（8.2 手順1）', function () {
    // 課金と確定の間に座席を失う経路そのものは ReservationServiceTest が担保する。
    // ここでは、その結果を受けた画面の応答を固定する。
    ['screening' => $screening] = readyForConfirm();
    fakeStripeService(settledCharge(3000));

    app()->instance(ReservationService::class, new class(app(StripeService::class), app(SeatLockService::class)) extends ReservationService
    {
        public function payAndConfirm(
            Screening $screening,
            PriceBreakdown $breakdown,
            Purchaser $purchaser,
            string $holderKey,
            ?string $paymentMethodId,
            ?Reservation $pending = null,
        ): PaymentAttempt {
            return PaymentAttempt::seatsUnavailable('front.reservation.errors.seats_taken');
        }
    });

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->call('confirm')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.seats_taken'))
        ->assertSee(route('front.reservation.seats', ['id' => $screening->id]))
        // 課金は済んでいるため、同じ画面での再試行は求めない。
        ->assertDontSee(__('front.reservation.confirm.submit'));

    expect(ReservationSeat::count())->toBe(0);
});

it('券種（P-35）が揃っていない場合は券種選択へ戻す（4.3.14）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, ...$seats);
    agreeToTerms($screening);
    enterGuestInfo($screening);
    fakeStripeService(settledCharge(3000));

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.ticket_selection_required'))
        ->call('confirm')
        ->assertNoRedirect();

    expect(Reservation::count())->toBe(0);
});

it('保持中の座席が期限切れになると確定できない（6.4.1-3）', function () {
    ['screening' => $screening] = readyForConfirm();
    fakeStripeService(settledCharge(3000));

    $component = Livewire::test(Confirm::class, ['screening' => $screening]);

    $this->travel(SeatLockService::PAYMENT_LOCK_MINUTES + 1)->minutes();

    $component->call('confirm')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.lock_expired'));

    // 前提の判定で止まるため、課金そのものを行わない。
    expect(Reservation::count())->toBe(0);
});

it('会員の予約は会員情報を表示し、user_id で確定する（4.3.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture(2);
    $user = User::factory()->create(['name' => '祇園　花子', 'email' => 'member@example.test']);
    holdSeatsForScreening($screening, 'user:'.$user->id, ...$seats);
    agreeToTerms($screening);
    assignTickets($screening, adultTicket(2000), ...$seats);
    app(ReservationDraft::class)->putPaymentMethod($screening, 'pm_card_visa');
    // 大人2,000円×2席。ペア割（6.5.2）で3,000円。
    fakeStripeService(settledCharge(3000));

    Livewire::actingAs($user)
        ->test(Confirm::class, ['screening' => $screening])
        ->assertSee('祇園　花子')
        ->assertSee('member@example.test')
        ->call('confirm')
        ->assertRedirectContains('/complete');

    expect(Reservation::firstOrFail()->user_id)->toBe($user->id);
});

it('販売期間外の上映回では確定を求めず、復帰先も出さない（4.3.1）', function () {
    ['screening' => $screening] = readyForConfirm();
    fakeStripeService(settledCharge(3000));

    $screening->update([
        'starts_at' => CarbonImmutable::now()->subHour(),
        'ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    Livewire::test(Confirm::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.out_of_sale'))
        ->assertDontSee(__('front.reservation.confirm.submit'))
        ->call('confirm')
        ->assertNoRedirect();

    expect(Reservation::count())->toBe(0);
});

it('通信に失敗した場合は予約を保持し、再試行を同じ冪等キーで送る（17.3-4）', function () {
    // 予約を作り直すと冪等キーが変わり、最初の課金が成立していた場合に二重課金になる。
    ['screening' => $screening] = readyForConfirm();
    $stripe = fakeStripeService(StripeException::requestFailed());

    $component = Livewire::test(Confirm::class, ['screening' => $screening])
        ->call('confirm')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.payment_failed'));

    $reservationId = Reservation::firstOrFail()->id;

    $component->assertSet('pendingReservationId', $reservationId);

    // 再試行は同じ予約＝同じ冪等キーで送る（Stripe が最初の応答を再生する）。
    $stripe->charge = settledCharge(3000);

    $component->call('confirm')->assertRedirectContains('/complete');

    expect(Reservation::count())->toBe(1)
        ->and($stripe->charges[1]['idempotencyKey'])->toBe($stripe->charges[0]['idempotencyKey']);
});

it('追加認証の間に座席を失った場合は課金を返金する（8.2）', function () {
    ['screening' => $screening] = readyForConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, 3000, 'pi_3ds_secret'),
        // 認証は完了しており、課金は成立している。
        settledCharge(3000, 'pi_3ds'),
    );

    $component = Livewire::test(Confirm::class, ['screening' => $screening])->call('confirm');

    // 認証している間にロックが切れた（画面は前提を満たせなくなる）。
    SeatLock::where('screening_id', $screening->id)->delete();

    $component->call('completeAuthentication')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.seats_taken'))
        ->assertSee(route('front.reservation.seats', ['id' => $screening->id]));

    expect($stripe->refunds)->toBe(['pi_3ds'])
        ->and(Reservation::firstOrFail()->refunded_at)->not->toBeNull()
        ->and(ReservationSeat::count())->toBe(0);
});
