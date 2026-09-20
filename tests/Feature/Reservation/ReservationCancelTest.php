<?php

use App\Enums\CancellationOutcome;
use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Services\ReservationService;
use App\Services\SeatLockService;
use App\Services\StripeException;
use Carbon\CarbonImmutable;

/*
 * 予約キャンセル（4.4 / 4.3.18）。`ReservationService::cancel()` の判定・座席の解放・
 * 返金と、失敗した場合の残り方を固定する。
 *
 * Stripe とは通信しない（`fakeStripeService()` が差し替える）。画面側の導線は
 * tests/Feature/Front/LookupTest.php が担保する。
 */

/**
 * キャンセルできる状態の予約（`paid`・上映は十分先）を1件作る。
 *
 * @return array{reservation: Reservation, screening: Screening, seats: array<int, Seat>}
 */
function cancellableReservation(
    int $seatCount = 2,
    int $seatAmount = 2000,
    ?CarbonImmutable $startsAt = null,
    ?CarbonImmutable $checkedInAt = null,
    ?string $paymentIntentId = 'pi_test_settled',
): array {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture($seatCount);

    if ($startsAt !== null) {
        $screening->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2)]);
        $screening->refresh();
    }

    $ticketType = adultTicket($seatAmount);

    $reservation = Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'guest_name' => '祇園　太郎',
        'guest_name_kana' => 'ギオン　タロウ',
        'contact_type' => ContactType::Guest,
        'guest_email' => 'guest@example.test',
        'guest_phone' => '0751234567',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Paid,
        'total_amount' => $seatAmount * $seatCount,
    ]);

    // 状態遷移列は `$fillable` から外れている（6.1追記表）。
    $reservation->forceFill([
        'stripe_payment_intent_id' => $paymentIntentId,
        'checked_in_at' => $checkedInAt,
    ])->save();

    foreach ($seats as $seat) {
        ReservationSeat::create([
            'reservation_id' => $reservation->id,
            'screening_id' => $screening->id,
            'seat_id' => $seat->id,
            'ticket_type_id' => $ticketType->id,
            'amount' => $seatAmount,
        ]);
    }

    return ['reservation' => $reservation, 'screening' => $screening, 'seats' => $seats];
}

it('予約をキャンセルし、座席を再販可能に戻して返金する（4.4）', function () {
    $stripe = fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation] = cancellableReservation();

    $result = app(ReservationService::class)->cancel($reservation);

    expect($result->outcome)->toBe(CancellationOutcome::Cancelled)
        ->and($result->messageKey)->toBe('front.cancel.done')
        // 4.4-4。Refund API を1回だけ呼ぶ（17.3-5）。
        ->and($stripe->refunds)->toBe(['pi_test_settled']);

    $reservation->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->cancelled_at)->not->toBeNull()
        ->and($reservation->refunded_at)->not->toBeNull();

    // 4.4 の処理2 / 6.4.2。`released_at` が入ると生成列が NULL になり一意制約から外れる。
    $rows = ReservationSeat::where('reservation_id', $reservation->id)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->whereNull('released_at'))->toHaveCount(0)
        ->and($rows->whereNull('active_seat_id'))->toHaveCount(2);
});

it('キャンセルした座席を他の利用者が取得できる（6.4.2）', function () {
    fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation, 'screening' => $screening, 'seats' => $seats] = cancellableReservation();

    // キャンセル前は確定済みの座席として取得を拒まれる（`acquire()` の条件5）。
    expect(app(SeatLockService::class)->acquire($screening, $seats[0], 'session:other'))->toBeFalse();

    app(ReservationService::class)->cancel($reservation);

    expect(app(SeatLockService::class)->acquire($screening, $seats[0], 'session:other'))->toBeTrue();

    // キャンセルは座席ロックを作らない（確定時に削除済みの行が復活していないこと）。
    expect(SeatLock::where('screening_id', $screening->id)->where('holder_key', 'session:other')->count())->toBe(1);
});

it('上映開始の20分前を過ぎるとキャンセルできない（4.4-1）', function (int $minutesUntilStart, bool $cancellable) {
    $stripe = fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation] = cancellableReservation(
        startsAt: CarbonImmutable::now()->addMinutes($minutesUntilStart),
    );

    $result = app(ReservationService::class)->cancel($reservation);

    expect($result->outcome)->toBe($cancellable ? CancellationOutcome::Cancelled : CancellationOutcome::Rejected);

    $reservation->refresh();

    expect($reservation->status)->toBe($cancellable ? ReservationStatus::Cancelled : ReservationStatus::Paid);

    if (! $cancellable) {
        // 拒否された場合は課金にも座席にも触れない。
        expect($result->messageKey)->toBe('front.cancel.errors.deadline_passed')
            ->and($stripe->refunds)->toBe([])
            ->and(ReservationSeat::where('reservation_id', $reservation->id)->whereNull('released_at')->count())->toBe(2);
    }
})->with([
    '21分前はキャンセルできる' => [21, true],
    '20分前ちょうどは締め切る' => [20, false],
    '19分前はキャンセルできない' => [19, false],
]);

it('入場済みの予約はキャンセルできない（4.4-5）', function () {
    $stripe = fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation] = cancellableReservation(checkedInAt: CarbonImmutable::now()->subMinutes(5));

    $result = app(ReservationService::class)->cancel($reservation);

    expect($result->outcome)->toBe(CancellationOutcome::Rejected)
        ->and($result->messageKey)->toBe('front.cancel.errors.checked_in')
        ->and($stripe->refunds)->toBe([]);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);
});

it('キャンセル済みの予約を二度キャンセルしても返金は1回だけ（17.3-5）', function () {
    $stripe = fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation] = cancellableReservation();

    app(ReservationService::class)->cancel($reservation);
    $second = app(ReservationService::class)->cancel($reservation->refresh());

    expect($second->outcome)->toBe(CancellationOutcome::Rejected)
        ->and($second->messageKey)->toBe('front.cancel.errors.not_cancellable')
        // 2度目は状態の判定で止まるため、Refund API へ到達しない。
        ->and($stripe->refunds)->toBe(['pi_test_settled']);
});

it('返金に失敗してもキャンセルは成立させ、座席を戻したままにする（4.3.18）', function () {
    $stripe = fakeStripeService(settledCharge(4000));
    $stripe->refundError = StripeException::refundFailed();
    ['reservation' => $reservation] = cancellableReservation();

    $result = app(ReservationService::class)->cancel($reservation);

    expect($result->outcome)->toBe(CancellationOutcome::CancelledWithoutRefund)
        ->and($result->messageKey)->toBe('front.cancel.refund_pending');

    $reservation->refresh();

    // **予約を `paid` へ戻さない。** 戻すと、既に他者が取得した座席を奪い返しうる。
    expect($reservation->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->refunded_at)->toBeNull()
        // 運用が追えるよう PaymentIntent のIDは残す（10章 B-02 の除外条件でもある）。
        ->and($reservation->stripe_payment_intent_id)->toBe('pi_test_settled')
        ->and(ReservationSeat::where('reservation_id', $reservation->id)->whereNull('released_at')->count())->toBe(0);
});

it('支払金額0円の予約は返金を試みない（4.5.2）', function () {
    $stripe = fakeStripeService(settledCharge(0));
    ['reservation' => $reservation] = cancellableReservation(seatAmount: 0, paymentIntentId: null);

    $result = app(ReservationService::class)->cancel($reservation);

    expect($result->outcome)->toBe(CancellationOutcome::Cancelled)
        ->and($result->messageKey)->toBe('front.cancel.done_no_refund')
        ->and($stripe->refunds)->toBe([]);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});
