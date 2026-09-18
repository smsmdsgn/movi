<?php

use App\Enums\PaymentOutcome;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\User;
use App\Services\CardCharge;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Services\Purchaser;
use App\Services\ReservationService;
use App\Services\SeatLockService;
use App\Services\SeatPrice;
use App\Services\StripeException;
use App\Services\StripeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/*
 * 予約確定（13.4.7 / 8.2）。課金と確定トランザクションを固定する。
 *
 * 13.4.7 が実装時に追加するよう求めている2つの観点をここで担保する。
 * - 他シアターの座席IDを渡すと拒否される
 * - 期限切れ・他人のロックでは決済確定が拒否される
 *
 * Stripe とは通信しない（`StripeService` を差し替える）。
 */

/**
 * 座席を保持し、券種を割り当てた状態を作る。
 *
 * @return array{screening: Screening, seats: list<Seat>, breakdown: PriceBreakdown, holderKey: string}
 */
function readyToConfirm(int $seatCount = 2, int $price = 2000): array
{
    $fixture = makeReservationFixture($seatCount);
    $locks = app(SeatLockService::class);
    $holderKey = $locks->holderKey();

    holdSeatsForScreening($fixture['screening'], $holderKey, ...$fixture['seats']);

    $ticketType = adultTicket($price);
    $tickets = [];

    foreach ($fixture['seats'] as $seat) {
        $tickets[$seat->id] = $ticketType->id;
    }

    return [
        'screening' => $fixture['screening'],
        'seats' => $fixture['seats'],
        'breakdown' => app(PricingService::class)->calculate($fixture['screening'], $tickets),
        'holderKey' => $holderKey,
    ];
}

function guestPurchaser(): Purchaser
{
    return Purchaser::guest([
        'name' => '祇園　太郎',
        'name_kana' => 'ギオン　タロウ',
        'phone' => '0751234567',
        'email' => 'guest@example.test',
    ]);
}

it('課金に成功すると予約を確定し、座席ロックを削除する（8.2 手順1〜4）', function () {
    ['screening' => $screening, 'seats' => $seats, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Confirmed);

    $reservation = $attempt->reservation?->refresh();

    expect($reservation?->status)->toBe(ReservationStatus::Paid)
        ->and($reservation?->entry_code)->toHaveLength(32)
        ->and($reservation?->reservation_no)->toHaveLength(8)
        ->and($reservation?->total_amount)->toBe($breakdown->total())
        ->and($reservation?->stripe_payment_intent_id)->toBe('pi_test_settled')
        // 確定後は期限切れ（B-02）の対象にしない。
        ->and($reservation?->expires_at)->toBeNull()
        // 手順3: 席ごとの確定額を保存する（6.5.5）。
        ->and(ReservationSeat::where('reservation_id', $reservation?->id)->count())->toBe(count($seats))
        // 手順4: ロックは削除され、以降は予約座席の一意制約が排他を担う（6.4.1-6）。
        ->and(SeatLock::where('screening_id', $screening->id)->count())->toBe(0)
        // 課金はサーバーが算出した金額で1回だけ行う。
        ->and($stripe->charges)->toHaveCount(1)
        ->and($stripe->charges[0]['amount'])->toBe($breakdown->total())
        ->and($stripe->refunds)->toBe([]);
});

it('支払金額が0円なら課金せずに確定する（4.5.2）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm(price: 0);
    $stripe = fakeStripeService(settledCharge(0));

    expect($breakdown->isFullyCovered())->toBeTrue();

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, null,
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Confirmed)
        ->and($attempt->reservation?->status)->toBe(ReservationStatus::Paid)
        ->and($stripe->charges)->toBe([]);
});

it('ロックが既に切れている場合は課金せず、予約も作らない（8.2 手順1 / 13.4.7）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));

    SeatLock::where('screening_id', $screening->id)->update(['expires_at' => CarbonImmutable::now()->subMinute()]);

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        ->and($attempt->messageKey)->toBe('front.reservation.errors.lock_expired')
        ->and($stripe->charges)->toBe([])
        // 期限を持たない `pending` 予約（B-02 の対象外）を作らない。
        ->and(Reservation::count())->toBe(0);
});

it('他人のロックでは確定せず、課金もしない（6.4.2 / 13.4.7）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));

    // 座席を保持しているのは別の利用者。自分の保持者キーでは確定できない。
    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), 'session:someone-else', 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        ->and($stripe->charges)->toBe([])
        ->and(ReservationSeat::count())->toBe(0);
});

it('課金の最中にロックが切れた場合は返金してやり直しを促す（8.2 手順1）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));

    // 課金の応答を待つ間に期限が到来した状態（確定の直前で初めて分かる）。
    $stripe->onCharge = function () use ($screening) {
        SeatLock::where('screening_id', $screening->id)->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
    };

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::SeatsUnavailable)
        ->and($attempt->messageKey)->toBe('front.reservation.errors.seats_taken')
        ->and($stripe->refunds)->toBe(['pi_test_settled'])
        ->and(ReservationSeat::count())->toBe(0)
        // 予約は `pending` のまま残り、返金済みであることを記録する（17.3-5）。
        ->and(Reservation::first()?->status)->toBe(ReservationStatus::Pending)
        ->and(Reservation::first()?->refunded_at)->not->toBeNull();
});

it('返金に失敗した場合は refunded_at を立てず、劇場への連絡を案内する（4.3.15 / 17.3-5）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));
    $stripe->refundError = StripeException::refundFailed();
    $stripe->onCharge = function () use ($screening) {
        SeatLock::where('screening_id', $screening->id)->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
    };

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::SeatsUnavailable)
        ->and($attempt->messageKey)->toBe('front.reservation.errors.refund_failed')
        // 返金できていないため `refunded_at` は立てない。PaymentIntent のIDは残り、
        // 運用側が追跡できる。
        ->and(Reservation::first()?->refunded_at)->toBeNull()
        ->and(Reservation::first()?->stripe_payment_intent_id)->toBe('pi_test_settled');
});

it('他シアターの座席IDを渡すと拒否される（13.4.7）', function () {
    // DB制約（複合外部キー）では表現しないため、確定前にサービスが検証する。
    ['screening' => $screening, 'holderKey' => $holderKey] = readyToConfirm();

    $otherTheater = createTheater();
    makeSeatsWithSurcharge($otherTheater, 1, 0);
    $foreignSeat = Seat::where('theater_id', $otherTheater->id)->firstOrFail();
    $ticketType = adultTicket(2000);

    // ロック自体は直接作る（`SeatLockService::acquire()` は条件2で拒むため）。
    SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $foreignSeat->id,
        'holder_key' => $holderKey,
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
    ]);

    $breakdown = new PriceBreakdown([new SeatPrice($foreignSeat->id, $ticketType->id, 2000, 0)]);
    $stripe = fakeStripeService(settledCharge(2000));

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::SeatsUnavailable)
        ->and($stripe->refunds)->toBe(['pi_test_settled'])
        ->and(ReservationSeat::count())->toBe(0);
});

it('確定済みの座席と競合した場合は一意制約で弾き、返金する（6.4.2）', function () {
    ['screening' => $screening, 'seats' => $seats, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));

    // 自分がロックを保持したまま、別の予約が同じ座席を確定させた状態
    // （ロックの再検証を通り抜ける同時実行の最終防波堤）。
    $other = Reservation::create([
        'reservation_no' => '99999999',
        'contact_type' => 'guest',
        'guest_name' => '先客',
        'guest_email' => 'other@example.test',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Paid->value,
        'total_amount' => 2000,
        'entry_code' => str_repeat('a', 32),
    ]);

    ReservationSeat::create([
        'reservation_id' => $other->id,
        'screening_id' => $screening->id,
        'seat_id' => $seats[0]->id,
        'ticket_type_id' => $breakdown->seats[0]->ticketTypeId,
        'amount' => 2000,
    ]);

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::SeatsUnavailable)
        ->and($stripe->refunds)->toBe(['pi_test_settled'])
        // ロールバックされ、自分の予約座席は1件も残らない。
        ->and(ReservationSeat::where('reservation_id', '!=', $other->id)->count())->toBe(0);
});

it('カードが拒否された場合は予約を pending のまま残す（8.2「決済失敗時の扱い」）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(CardCharge::declined('pi_test_declined'));

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        ->and($attempt->messageKey)->toBe('front.reservation.errors.payment_failed')
        ->and(Reservation::first()?->status)->toBe(ReservationStatus::Pending)
        // 課金が成立していないため返金しない。
        ->and($stripe->refunds)->toBe([])
        // 座席ロックは維持され、決済のやり直しができる。
        ->and(SeatLock::where('screening_id', $screening->id)->count())->toBeGreaterThan(0);
});

it('成立したが金額が一致しない決済は、取り消さず返金する（17.3-3 / 17.3-5）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    // Stripe 側の金額が要求額と異なる（改ざん・取り違え）。**`succeeded` は取り消せない**
    // ため、`cancel` ではなく返金しなければ課金が残る。
    $stripe = fakeStripeService(settledCharge($breakdown->total() - 1));

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::SeatsUnavailable)
        ->and($stripe->refunds)->toBe(['pi_test_settled'])
        ->and($stripe->cancellations)->toBe([])
        ->and(ReservationSeat::count())->toBe(0);
});

it('成立していない決済は取り消す（返金しない）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    // 認証も拒否も無いまま未確定で返った場合（`requires_payment_method` 等）。
    $stripe = fakeStripeService(CardCharge::fromIntent('pi_unsettled', 'requires_payment_method', $breakdown->total(), null));

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        ->and($stripe->cancellations)->toBe(['pi_unsettled'])
        ->and($stripe->refunds)->toBe([]);
});

it('通信に失敗した場合は予約を保持し、同じ冪等キーで送り直せるようにする（17.3-4）', function () {
    // 課金が成立したかどうかが不明な状態。予約を作り直すと冪等キーが変わり、
    // 二重課金になりうる。
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(StripeException::requestFailed());
    $service = app(ReservationService::class);

    $first = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    expect($first->outcome)->toBe(PaymentOutcome::Failed)
        ->and($first->reservation)->not->toBeNull();

    // 同じ予約を渡した再送は、予約を作り直さず同じ冪等キーを使う。
    $stripe->charge = settledCharge($breakdown->total());
    $second = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa', $first->reservation);

    expect($second->outcome)->toBe(PaymentOutcome::Confirmed)
        ->and(Reservation::count())->toBe(1)
        ->and($stripe->charges[1]['idempotencyKey'])->toBe($stripe->charges[0]['idempotencyKey']);
});

it('内容が変わった予約は使い回さず、作り直す（6.5.5）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));
    $service = app(ReservationService::class);

    // 別の金額で作られた予約。使い回すと total_amount と席ごとの合計が食い違う。
    $stale = Reservation::create([
        'reservation_no' => '11111111',
        'contact_type' => 'guest',
        'guest_name' => '古い予約',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Pending->value,
        'total_amount' => $breakdown->total() + 500,
    ]);

    $attempt = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa', $stale);

    expect($attempt->outcome)->toBe(PaymentOutcome::Confirmed)
        ->and($attempt->reservation?->id)->not->toBe($stale->id)
        ->and($attempt->reservation?->total_amount)->toBe($breakdown->total());
});

it('3Dセキュアの最中に座席を失った場合も課金を放置しない（8.2）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
        // 認証は完了しており、課金は成立している。
        settledCharge($breakdown->total(), 'pi_3ds'),
    );
    $service = app(ReservationService::class);

    $pending = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    // 認証している間にロックが切れ、画面が前提を満たせなくなった。
    SeatLock::where('screening_id', $screening->id)->delete();

    $attempt = $service->abandon($pending->reservation ?? new Reservation);

    expect($attempt->outcome)->toBe(PaymentOutcome::SeatsUnavailable)
        ->and($stripe->refunds)->toBe(['pi_3ds'])
        ->and(Reservation::first()?->refunded_at)->not->toBeNull();
});

it('3Dセキュアを中断した予約の後始末では、成立していない課金を取り消す', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
    );
    $service = app(ReservationService::class);

    $pending = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    $attempt = $service->abandon($pending->reservation ?? new Reservation);

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        ->and($stripe->cancellations)->toBe(['pi_3ds'])
        ->and($stripe->refunds)->toBe([]);
});

it('追加認証が必要な場合は確定せず、client secret を返す（3Dセキュア）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    fakeStripeService(CardCharge::fromIntent('pi_test_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'pi_test_3ds_secret'));

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::RequiresAuthentication)
        ->and($attempt->clientSecret)->toBe('pi_test_3ds_secret')
        ->and($attempt->reservation?->status)->toBe(ReservationStatus::Pending)
        // 再開に必要な PaymentIntent のIDを予約に残す。
        ->and($attempt->reservation?->stripe_payment_intent_id)->toBe('pi_test_3ds')
        ->and(ReservationSeat::count())->toBe(0);
});

it('追加認証の完了後、サーバーが再取得して確定する（17.3-3）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_test_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
        settledCharge($breakdown->total(), 'pi_test_3ds'),
    );
    $service = app(ReservationService::class);

    $pending = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    $attempt = $service->completeAuthentication(
        $pending->reservation ?? new Reservation, $screening, $breakdown, $holderKey,
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Confirmed)
        ->and($attempt->reservation?->refresh()->status)->toBe(ReservationStatus::Paid)
        ->and(ReservationSeat::count())->toBe(count($breakdown->seats))
        ->and($stripe->cancellations)->toBe([]);
});

it('追加認証が完了していない場合は確定せず、PaymentIntent を取り消す', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_test_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
        // 認証を中断した場合、再取得しても `succeeded` にならない。
        CardCharge::fromIntent('pi_test_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
    );
    $service = app(ReservationService::class);

    $pending = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    $attempt = $service->completeAuthentication(
        $pending->reservation ?? new Reservation, $screening, $breakdown, $holderKey,
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        ->and($stripe->cancellations)->toBe(['pi_test_3ds'])
        ->and($stripe->refunds)->toBe([])
        ->and(ReservationSeat::count())->toBe(0);
});

it('通信に失敗した場合は決済失敗として扱う（17.9-1）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    fakeStripeService(StripeException::requestFailed());

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        // 例外のメッセージそのものは画面に出さない（文言キーのみ）。
        ->and($attempt->messageKey)->toBe('front.reservation.errors.payment_failed')
        ->and(ReservationSeat::count())->toBe(0);
});

it('冪等キーを変えずに済むよう、同じ予約を渡した再試行では予約を作り直さない', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));
    $service = app(ReservationService::class);

    $first = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    expect($stripe->charges[0]['idempotencyKey'])->toBe('reservation:'.$first->reservation?->id);
});

it('会員の予約は user_id で保存し、連絡先を複製しない（6.1.2）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    fakeStripeService(settledCharge($breakdown->total()));
    $user = User::factory()->create();

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, Purchaser::member($user), $holderKey, 'pm_card_visa',
    );

    $reservation = $attempt->reservation?->refresh();

    expect($reservation?->user_id)->toBe($user->id)
        ->and($reservation?->guest_email)->toBeNull()
        ->and($reservation?->guest_name)->toBeNull();
});

it('確定済みの状態を持つモデルは使い回さず、新しい予約を作る（再試行時の回帰）', function () {
    // `DB::transaction()` はデッドロック時に同じクロージャを再実行するが、**Eloquent の
    // モデルはロールバックで巻き戻らない**。前の試行で `status = paid` を代入・保存済みの
    // インスタンスを使い回すと、2回目は dirty にならず UPDATE から落ち、データベース上は
    // `pending` のまま「確定成功」を返す。確定処理が予約を読み直していれば起きない。
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));

    // 「1回目の試行を終えた直後」のモデルを再現する（DBは `pending`、メモリ上は `paid`）。
    $stale = Reservation::create([
        'reservation_no' => '66666666',
        'contact_type' => 'guest',
        'guest_name' => '再試行',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Pending->value,
        'total_amount' => $breakdown->total(),
        'expires_at' => CarbonImmutable::now()->addMinutes(15),
    ]);
    $stale->status = ReservationStatus::Paid;
    $stale->expires_at = null;
    $stale->syncOriginal();

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa', $stale,
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Confirmed)
        // 確定済みの状態を持つモデルは使い回さない（冪等キーの再生を避ける）。
        ->and($attempt->reservation?->id)->not->toBe($stale->id)
        // 確定した予約は、データベース上も `paid` であること（ここが回帰の要点。
        // 確定処理が予約を読み直さないと、`status` が dirty にならず `pending` のまま残る）。
        ->and(Reservation::whereKey($attempt->reservation?->id)->value('status'))->toBe(ReservationStatus::Paid)
        ->and(Reservation::whereKey($attempt->reservation?->id)->value('expires_at'))->toBeNull()
        ->and($attempt->reservation?->entry_code)->not->toBeNull()
        // 使い回さなかった予約には手を付けない。
        ->and(Reservation::whereKey($stale->id)->value('status'))->toBe(ReservationStatus::Pending)
        ->and($stripe->refunds)->toBe([]);
});

it('0円の予約で座席を確保できなかった場合は、返金に触れない文言を返す（4.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm(price: 0);
    $stripe = fakeStripeService(settledCharge(0));

    // 先に別の予約が同じ座席を確定させている。
    $other = Reservation::create([
        'reservation_no' => '88888888',
        'contact_type' => 'guest',
        'guest_name' => '先客',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Paid->value,
        'total_amount' => 0,
        'entry_code' => str_repeat('b', 32),
    ]);

    ReservationSeat::create([
        'reservation_id' => $other->id,
        'screening_id' => $screening->id,
        'seat_id' => $seats[0]->id,
        'ticket_type_id' => $breakdown->seats[0]->ticketTypeId,
        'amount' => 0,
    ]);

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, null,
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::SeatsUnavailable)
        ->and($attempt->messageKey)->toBe('front.reservation.errors.seats_taken_no_payment')
        ->and($stripe->refunds)->toBe([]);
});

it('再取得の通信に失敗した場合も予約を捨てない（17.3-4）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
        StripeException::requestFailed(),
    );
    $service = app(ReservationService::class);

    $pending = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    $attempt = $service->completeAuthentication(
        $pending->reservation ?? new Reservation, $screening, $breakdown, $holderKey,
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        // 成否が不明なため、同じ予約で検証をやり直せるようにする。
        ->and($attempt->reservation?->id)->toBe($pending->reservation?->id)
        ->and($stripe->cancellations)->toBe([]);
});

it('認証中に内容が変わった場合は、内容の変更として返金する（17.3-3）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
        settledCharge($breakdown->total(), 'pi_3ds'),
    );
    $service = app(ReservationService::class);

    $pending = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    // 別のタブで券種が変わり、支払金額が課金額と一致しなくなった。
    $cheaper = new PriceBreakdown([new SeatPrice($breakdown->seats[0]->seatId, $breakdown->seats[0]->ticketTypeId, 500, 0)]);

    $attempt = $service->completeAuthentication(
        $pending->reservation ?? new Reservation, $screening, $cheaper, $holderKey,
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::SeatsUnavailable)
        ->and($attempt->messageKey)->toBe('front.reservation.errors.contents_changed')
        ->and($stripe->refunds)->toBe(['pi_3ds']);
});

it('返金済みの予約は使い回さない', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));
    $service = app(ReservationService::class);

    $refunded = Reservation::create([
        'reservation_no' => '77777777',
        'contact_type' => 'guest',
        'guest_name' => '返金済み',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Pending->value,
        'total_amount' => $breakdown->total(),
    ]);
    $refunded->refunded_at = CarbonImmutable::now();
    $refunded->save();

    $attempt = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa', $refunded);

    expect($attempt->outcome)->toBe(PaymentOutcome::Confirmed)
        ->and($attempt->reservation?->id)->not->toBe($refunded->id);
});

it('確定済みの予約を渡して再確定しても、返金しない（同一予約の並走）', function () {
    // 同一の課金で2本の確定が並走すると、後から入った側は「ロックが無い（先行が削除した）」
    // と判断する。そこで返金すると、**有効な入場コードを持つ予約の代金だけが返る**。
    // 使い回しの拒否と、確定トランザクション内の `paid` 判定の2段で防ぐ。
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));
    $service = app(ReservationService::class);

    $first = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');
    $reservation = $first->reservation;

    expect($first->outcome)->toBe(PaymentOutcome::Confirmed);

    // 先行の確定でロックは削除済み。後続は同じ予約（同じ冪等キー）で入ってくる。
    $second = $service->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa', $reservation,
    );

    expect($second->outcome)->not->toBe(PaymentOutcome::SeatsUnavailable)
        // 確定済みの予約が返金されていないこと（ここが要点）。
        ->and($stripe->refunds)->toBe([])
        ->and(Reservation::whereKey($reservation?->id)->value('refunded_at'))->toBeNull()
        ->and(Reservation::whereKey($reservation?->id)->value('status'))->toBe(ReservationStatus::Paid);
});

it('座席が1件も無い内訳では予約を作らない（13.4.7）', function () {
    ['screening' => $screening, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge(0));

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, new PriceBreakdown([]), guestPurchaser(), $holderKey, null,
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Failed)
        ->and(Reservation::count())->toBe(0)
        ->and($stripe->charges)->toBe([]);
});

it('カードが拒否された場合は PaymentIntent のIDを保存しない（10章 B-02 の除外条件）', function () {
    // 取り消し済みの PaymentIntent を持つ `pending` が残ると、B-02 の除外条件
    // （課金が残っている予約）に日常的なノイズが混ざる。
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(CardCharge::declined('pi_declined'));

    app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect(Reservation::first()?->stripe_payment_intent_id)->toBeNull()
        ->and($stripe->cancellations)->toBe(['pi_declined']);
});

it('確定に失敗した場合、例外の種別と予約IDだけを記録する（4.3.15 / 17.9-1）', function () {
    ['screening' => $screening, 'seats' => $seats, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    fakeStripeService(settledCharge($breakdown->total()));

    // 先客が同じ座席を確定済み＝一意制約違反で確定できない。
    $other = Reservation::create([
        'reservation_no' => '55555555',
        'contact_type' => 'guest',
        'guest_name' => '先客',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Paid->value,
        'total_amount' => 2000,
        'entry_code' => str_repeat('c', 32),
    ]);

    ReservationSeat::create([
        'reservation_id' => $other->id,
        'screening_id' => $screening->id,
        'seat_id' => $seats[0]->id,
        'ticket_type_id' => $breakdown->seats[0]->ticketTypeId,
        'amount' => 2000,
    ]);

    Log::spy();

    app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        // 例外メッセージ（バインド値に入場コード等を含む）を出さないこと。
        return array_keys($context) === ['exception', 'reservation_id']
            && is_string($context['exception'])
            && is_int($context['reservation_id']);
    });
});

it('確定の直前に別の確定が完了していた場合、返金せず成功として扱う（同一予約の並走）', function () {
    // 並走の敗者は「先行が座席ロックを削除済み」の状態で確定に入る。そこで返金すると
    // **有効な入場コードを持つ予約の代金だけが返る**。確定処理は予約の `status` を
    // 座席ロックの検証より先に見る必要がある。
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(settledCharge($breakdown->total()));

    // 課金の応答を待つ間に、先行の確定が完了した状態を作る。**先行は座席ロックも
    // 削除する**（確定の手順4）ため、敗者から見ると「予約は `paid`・ロックは無い」になる。
    $stripe->onCharge = function () use ($screening) {
        Reservation::where('status', ReservationStatus::Pending)->update([
            'status' => ReservationStatus::Paid->value,
            'entry_code' => str_repeat('d', 32),
            'expires_at' => null,
        ]);

        SeatLock::where('screening_id', $screening->id)->delete();
    };

    $attempt = app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    expect($attempt->outcome)->toBe(PaymentOutcome::Confirmed)
        // 返金していないこと（ここが要点）。
        ->and($stripe->refunds)->toBe([])
        ->and($attempt->reservation?->status)->toBe(ReservationStatus::Paid)
        ->and(Reservation::first()?->refunded_at)->toBeNull();
});

it('3Dセキュアを中断した場合、取り消せた PaymentIntent のIDは予約から外す（10章 B-02）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
    );
    $service = app(ReservationService::class);

    $pending = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');

    // 認証の開始時点ではIDを保存している（再開のために必要）。
    expect(Reservation::first()?->stripe_payment_intent_id)->toBe('pi_3ds');

    $service->abandon($pending->reservation ?? new Reservation);

    expect($stripe->cancellations)->toBe(['pi_3ds'])
        // 取り消せたので課金は残っていない。B-02 の除外条件に居座らせない。
        ->and(Reservation::first()?->stripe_payment_intent_id)->toBeNull();
});

it('取り消せなかった PaymentIntent のIDは予約に残す（追跡のため）', function () {
    ['screening' => $screening, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    $stripe = fakeStripeService(
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
        CardCharge::fromIntent('pi_3ds', CardCharge::STATUS_REQUIRES_ACTION, $breakdown->total(), 'secret'),
    );
    $stripe->cancelSucceeds = false;
    $service = app(ReservationService::class);

    $pending = $service->payAndConfirm($screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa');
    $service->abandon($pending->reservation ?? new Reservation);

    expect(Reservation::first()?->stripe_payment_intent_id)->toBe('pi_3ds');
});

it('pending 予約の expires_at を、保持中のロックのうち最も早い期限に合わせる（B-02 の基準）', function () {
    ['screening' => $screening, 'seats' => $seats, 'breakdown' => $breakdown, 'holderKey' => $holderKey] = readyToConfirm();
    // 課金を失敗させ、`pending` 予約を残す。
    $stripe = fakeStripeService(CardCharge::declined(null));

    $earliest = CarbonImmutable::now()->addMinutes(4)->startOfSecond();
    SeatLock::where('seat_id', $seats[0]->id)->update(['expires_at' => $earliest]);
    SeatLock::where('seat_id', $seats[1]->id)->update(['expires_at' => CarbonImmutable::now()->addMinutes(12)]);

    app(ReservationService::class)->payAndConfirm(
        $screening, $breakdown, guestPurchaser(), $holderKey, 'pm_card_visa',
    );

    // ロックが切れた時点で座席は他の顧客へ渡るため、予約もそこで無効化されるべき（10章 B-02）。
    expect(Reservation::first()?->expires_at?->toDateTimeString())->toBe($earliest->toDateTimeString())
        ->and($stripe->charges)->toHaveCount(1);
});
