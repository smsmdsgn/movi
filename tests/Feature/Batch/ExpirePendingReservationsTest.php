<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\CardCharge;
use App\Services\ReservationService;
use App\Services\StripeException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

/*
 * 未決済予約の無効化（B-02、10章 / 4.3.3 / 4.3.19）。**課金が残っていないことを
 * 確かめてから倒すこと**と、3Dセキュアを中断した予約が恒久的に残らないこと
 * （12章 旧残課題35）を固定する。
 */

/** `pending` の予約を1件作る。既定はロックの期限を過ぎた状態（＝無効化の対象）。 */
function expirableReservation(
    ?CarbonImmutable $expiresAt = null,
    ?string $paymentIntentId = null,
    ?CarbonImmutable $refundedAt = null,
    ReservationStatus $status = ReservationStatus::Pending,
): Reservation {
    ['screening' => $screening] = makeReservationFixture(1);

    $reservation = Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'guest_name' => '祇園　太郎',
        'guest_name_kana' => 'ギオン　タロウ',
        'contact_type' => ContactType::Guest,
        'guest_email' => 'taro@example.test',
        'guest_phone' => '09012345678',
        'screening_id' => $screening->id,
        'status' => $status,
        'total_amount' => 2000,
        'expires_at' => $expiresAt ?? CarbonImmutable::now()->subMinute(),
    ]);

    if ($paymentIntentId !== null || $refundedAt !== null) {
        $reservation->forceFill([
            'stripe_payment_intent_id' => $paymentIntentId,
            'refunded_at' => $refundedAt,
        ])->save();
    }

    return $reservation;
}

/** 未確定の PaymentIntent（3Dセキュアの認証待ちで止まったもの）。 */
function unconfirmedCharge(string $id = 'pi_test_pending'): CardCharge
{
    return CardCharge::fromIntent($id, CardCharge::STATUS_REQUIRES_ACTION, 2000, 'secret');
}

it('ロックの期限を過ぎた pending を無効化する（10章 B-02）', function () {
    fakeStripeService(settledCharge(2000));
    $reservation = expirableReservation();

    $result = app(ReservationService::class)->expirePending();

    expect($result->expired)->toBe(1)
        ->and($result->withCharge)->toBe(0)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Expired)
        // 期限は役目を終える（`paid` と同じく期限を持たない終端になる）。
        ->and($reservation->expires_at)->toBeNull();
});

it('対象外の予約には触れない', function (Closure $make) {
    fakeStripeService(settledCharge(2000));
    $reservation = $make();
    $before = $reservation->status;

    expect(app(ReservationService::class)->expirePending()->expired)->toBe(0)
        ->and($reservation->refresh()->status)->toBe($before);
})->with([
    // まだロックが生きている。
    '期限内の pending' => [fn () => expirableReservation(expiresAt: CarbonImmutable::now()->addMinutes(5))],
    // 確定済み・終端の予約は期限を持たない。
    'paid' => [fn () => expirableReservation(expiresAt: null, status: ReservationStatus::Paid)],
    'cancelled' => [fn () => expirableReservation(expiresAt: null, status: ReservationStatus::Cancelled)],
    'expired' => [fn () => expirableReservation(expiresAt: null, status: ReservationStatus::Expired)],
]);

it('返金済みの予約は課金が残っていないため無効化する（10章 B-02 の除外条件）', function () {
    $stripe = fakeStripeService(settledCharge(2000));
    $reservation = expirableReservation(
        paymentIntentId: 'pi_test_refunded',
        refundedAt: CarbonImmutable::now()->subMinute(),
    );

    expect(app(ReservationService::class)->expirePending()->expired)->toBe(1)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Expired)
        // 返金済みと分かっているため Stripe へ問い合わせない。
        ->and($stripe->retrievals)->toBe([]);
});

it('課金が成立したままの予約は倒さず手がかりを残す（17.3-5 / 12章 残課題39）', function () {
    $stripe = fakeStripeService(settledCharge(2000), retrieved: settledCharge(2000, 'pi_test_settled'));
    $reservation = expirableReservation(paymentIntentId: 'pi_test_settled');

    $result = app(ReservationService::class)->expirePending();

    expect($result->expired)->toBe(0)
        ->and($result->withCharge)->toBe(1)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        // **PaymentIntent のIDを外さない。** 課金が残っていることを示す唯一の手がかり。
        ->and($reservation->stripe_payment_intent_id)->toBe('pi_test_settled')
        ->and($stripe->cancellations)->toBe([]);
});

it('3Dセキュアを中断した予約は取り消してから無効化する（12章 旧残課題35）', function () {
    $stripe = fakeStripeService(settledCharge(2000), retrieved: unconfirmedCharge('pi_test_abandoned'));
    $reservation = expirableReservation(paymentIntentId: 'pi_test_abandoned');

    $result = app(ReservationService::class)->expirePending();

    expect($result->expired)->toBe(1)
        ->and($result->withCharge)->toBe(0)
        ->and($stripe->cancellations)->toBe(['pi_test_abandoned'])
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Expired)
        // 取り消せたIDは外す。残すと「課金が残っている予約」の目印が偽物になる。
        ->and($reservation->stripe_payment_intent_id)->toBeNull();
});

it('取り消し済みの PaymentIntent には再度 cancel を投げない（4.3.19）', function () {
    // 前回の実行が取り消しに成功した直後に保存へ失敗した状態を作る。Stripe 側は
    // `canceled` だが、予約は `pending` のままIDを持っている。
    $stripe = fakeStripeService(
        settledCharge(2000),
        retrieved: CardCharge::fromIntent('pi_test_canceled', CardCharge::STATUS_CANCELED, 2000, null),
    );
    // 取り消し済みへ `cancel` を送れば Stripe はエラーを返す（＝取り消せない）。
    $stripe->cancelSucceeds = false;
    $reservation = expirableReservation(paymentIntentId: 'pi_test_canceled');

    $result = app(ReservationService::class)->expirePending();

    expect($result->expired)->toBe(1)
        ->and($result->withCharge)->toBe(0)
        // 再送していないこと。送っていれば失敗して倒せず、以後どの実行でも詰まる。
        ->and($stripe->cancellations)->toBe([])
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Expired)
        ->and($reservation->stripe_payment_intent_id)->toBeNull();
});

it('取り消せなかった場合は倒さず次回へ送る（4.3.19）', function () {
    $stripe = fakeStripeService(settledCharge(2000), retrieved: unconfirmedCharge('pi_test_stuck'));
    $stripe->cancelSucceeds = false;
    $reservation = expirableReservation(paymentIntentId: 'pi_test_stuck');

    $result = app(ReservationService::class)->expirePending();

    expect($result->expired)->toBe(0)
        ->and($result->withCharge)->toBe(1)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->stripe_payment_intent_id)->toBe('pi_test_stuck');
});

it('問い合わせに失敗した場合は倒さない（成否が不明なため）', function () {
    fakeStripeService(settledCharge(2000), retrieved: StripeException::requestFailed());
    $reservation = expirableReservation(paymentIntentId: 'pi_test_unknown');

    $result = app(ReservationService::class)->expirePending();

    expect($result->expired)->toBe(0)
        ->and($result->withCharge)->toBe(1)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('PaymentIntent を持たない予約は問い合わせずに無効化する（10章）', function () {
    $stripe = fakeStripeService(settledCharge(2000));
    $reservation = expirableReservation();

    expect(app(ReservationService::class)->expirePending()->expired)->toBe(1)
        ->and($stripe->retrievals)->toBe([])
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Expired);
});

it('読み取りの後に確定した予約を上書きしない（4.3.19）', function () {
    $reservation = expirableReservation(paymentIntentId: 'pi_test_racing');

    // **Stripe への問い合わせの最中に確定が成立した状況を作る。** 書き込みの直前に
    // 読み直して再判定しなければ、`paid` を `expired` で上書きする。
    //
    // 本番では「DBは `paid` なのに PaymentIntent は未確定」という組み合わせは起こらない。
    // ここで確かめたいのは**DB側の再判定が効くこと**であり、Stripe の応答は素通りさせる
    // ための値である（未確定にしておけば `hasNoRemainingCharge()` が true を返し、
    // `expireOne()` の判定まで到達する）。
    $stripe = fakeStripeService(settledCharge(2000), retrieved: unconfirmedCharge('pi_test_racing'));
    $stripe->onRetrieve = function () use ($reservation): void {
        Reservation::whereKey($reservation->id)->update([
            'status' => ReservationStatus::Paid,
            'expires_at' => null,
        ]);
    };

    $result = app(ReservationService::class)->expirePending();

    expect($result->expired)->toBe(0)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Paid)
        // 確定した予約の PaymentIntent を外さない（返金の手がかりを消さない）。
        ->and($reservation->stripe_payment_intent_id)->toBe('pi_test_racing');
});

it('期限ちょうどの pending は無効化の対象とする（`Reservation::active()` の裏返し）', function () {
    fakeStripeService(settledCharge(2000));
    $now = CarbonImmutable::now()->startOfSecond();
    CarbonImmutable::setTestNow($now);

    $reservation = expirableReservation(expiresAt: $now);

    // `active()` は `expires_at > now` を有効とするため、ちょうどは「有効ではない」。
    expect(Reservation::query()->active()->whereKey($reservation->id)->exists())->toBeFalse()
        ->and(app(ReservationService::class)->expirePending()->expired)->toBe(1)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Expired);

    CarbonImmutable::setTestNow();
});

it('上限に達した分は次回の実行で処理する（10章 B-02）', function () {
    fakeStripeService(settledCharge(2000));

    foreach (range(1, 3) as $ignored) {
        expirableReservation();
    }

    $first = app(ReservationService::class)->expirePending(limit: 2);

    expect($first->expired)->toBe(2)
        ->and($first->reachedLimit(2))->toBeTrue();

    $second = app(ReservationService::class)->expirePending(limit: 2);

    expect($second->expired)->toBe(1)
        ->and($second->reachedLimit(2))->toBeFalse()
        ->and(Reservation::where('status', ReservationStatus::Pending)->count())->toBe(0);
});

it('コマンドから実行できる（10章 B-02）', function () {
    fakeStripeService(settledCharge(2000));
    expirableReservation();

    $this->artisan('reservations:expire')
        ->expectsOutputToContain('未決済の予約を 1 件無効化しました。')
        ->assertSuccessful();

    expect(Reservation::where('status', ReservationStatus::Pending)->count())->toBe(0);
});

it('課金が残っている予約があればその旨を知らせる（17.3-5）', function () {
    fakeStripeService(settledCharge(2000), retrieved: settledCharge(2000, 'pi_test_settled'));
    expirableReservation(paymentIntentId: 'pi_test_settled');

    $this->artisan('reservations:expire')
        ->expectsOutputToContain('1 件は課金が残っているため無効化していません。')
        ->assertSuccessful();
});

it('不正な --limit は黙って解釈せず失敗させる', function () {
    fakeStripeService(settledCharge(2000));
    expirableReservation();

    $this->artisan('reservations:expire', ['--limit' => 'abc'])
        ->assertExitCode(Command::INVALID);

    expect(Reservation::where('status', ReservationStatus::Pending)->count())->toBe(1);
});

it('10分ごとに実行するようスケジュールへ登録する（10章 B-02）', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'reservations:expire'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(15);
});
