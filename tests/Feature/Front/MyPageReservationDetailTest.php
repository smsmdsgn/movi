<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Livewire\Front\MyPage\ReservationDetail;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\TicketType;
use App\Models\User;
use App\Services\StripeException;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * マイページの予約詳細（P-06、7.14 / 4.5.4）。**所有者以外が到達できないこと**
 * （17.2.1-1・2 / 17.15 T-11）と、会員の導線からキャンセルできること（4.4「操作導線」）
 * を固定する。
 *
 * 明細の項目そのものは P-07 と同じ部品（`x-front.reservation.detail`）を使うため、
 * ここでは所有者の判定と導線に絞る。判定・座席の解放・返金は
 * tests/Feature/Reservation/ReservationCancelTest.php が担保する。
 */

/**
 * 会員の予約を1件作る。
 *
 * @return array{reservation: Reservation, screening: Screening, seats: array<int, Seat>}
 */
function detailReservation(
    User $user,
    ReservationStatus $status = ReservationStatus::Paid,
    ?CarbonImmutable $startsAt = null,
    int $seatCount = 2,
    int $seatAmount = 2000,
): array {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture($seatCount);
    $ticketType = adultTicket($seatAmount);

    if ($startsAt !== null) {
        $screening->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2)]);
        $screening->refresh();
    }

    $reservation = Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'user_id' => $user->id,
        'contact_type' => ContactType::Member,
        'screening_id' => $screening->id,
        'status' => $status,
        'total_amount' => $seatAmount * $seatCount,
    ]);

    // 確定済みの予約は PaymentIntent のIDを持つ（4.3.15）。返金先になるため省略しない。
    $reservation->forceFill([
        'stripe_payment_intent_id' => 'pi_test_'.$reservation->id,
        'cancelled_at' => $status === ReservationStatus::Cancelled ? CarbonImmutable::now() : null,
    ])->save();

    foreach ($seats as $seat) {
        $row = ReservationSeat::create([
            'reservation_id' => $reservation->id,
            'screening_id' => $screening->id,
            'seat_id' => $seat->id,
            'ticket_type_id' => $ticketType->id,
            'amount' => $seatAmount,
        ]);

        // キャンセル済みは座席を占有しない（6.4.2）。
        if ($status === ReservationStatus::Cancelled) {
            $row->forceFill(['released_at' => CarbonImmutable::now()])->save();
        }
    }

    return ['reservation' => $reservation, 'screening' => $screening, 'seats' => $seats];
}

/** P-06 を開く。 */
function visitReservationDetail(User $user, Reservation $reservation): TestResponse
{
    return test()->actingAs($user)->get(route('front.mypage.reservation.show', ['id' => $reservation->id]));
}

it('予約内容を表示し、クロール対象外となる（7.14 / 19.3-6）', function () {
    createCinema('gion', '祇園ムビ');
    $user = User::factory()->create();
    ['reservation' => $reservation, 'seats' => $seats] = detailReservation($user, startsAt: CarbonImmutable::now()->addDay());

    visitReservationDetail($user, $reservation)
        ->assertOk()
        ->assertSee(__('front.mypage.detail.heading'))
        ->assertSee($reservation->formattedReservationNo())
        ->assertSee('テスト作品')
        ->assertSee($seats[0]->displayName())
        ->assertSee(TicketType::ADULT_NAME)
        ->assertSee(__('front.lookup.status.paid'))
        // 入場用QRコード・領収書は準備中の案内に留める（12章 残課題18 / 36）。
        ->assertSee(__('front.lookup.entry_pending'))
        ->assertSee(__('front.lookup.receipt_pending'))
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('お座席は座席表と同じ並び順で示す（7.19-4）', function () {
    createCinema('gion', '祇園ムビ');
    $user = User::factory()->create();
    ['reservation' => $reservation, 'seats' => $seats] = detailReservation($user, seatCount: 3);

    // **格子の座標を作成順の逆にする。** 既定のフィクスチャは作成順＝格子順のため、
    // 並べ替え（`Reservation::seatsInGridOrder()`）を外しても気づけない。
    // 行だけを入れ替える（列は触らない）。`(theater_id, grid_row, grid_col)` に
    // 一意制約があり、列を振り替えると途中の値が既存の座席と衝突する。
    foreach ($seats as $index => $seat) {
        $seat->forceFill(['grid_row' => count($seats) - $index])->save();
    }

    $expected = array_map(fn (Seat $seat): string => $seat->displayName(), array_reverse($seats));

    visitReservationDetail($user, $reservation)
        ->assertOk()
        ->assertSeeInOrder($expected);
});

it('他の会員の予約は 404 とし、存在の有無を明かさない（17.2.1-2 / 17.15 T-11）', function () {
    createCinema('gion', '祇園ムビ');
    $owner = User::factory()->create();
    $other = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($owner);

    visitReservationDetail($other, $reservation)->assertNotFound();
});

it('存在しない予約も同じく 404 を返す（17.2.1-2）', function () {
    createCinema('gion', '祇園ムビ');

    test()->actingAs(User::factory()->create())
        ->get(route('front.mypage.reservation.show', ['id' => 999]))
        ->assertNotFound();
});

it('非会員の予約（user_id が null）には到達できない（4.4「操作導線」）', function () {
    createCinema('gion', '祇園ムビ');
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user);

    // 予約完了（P-38）の「確定させたブラウザのセッション」による特例は持ち込まない。
    // マイページは所有者の一致だけを根拠とする（`ReservationPolicy::viewOwn()`）。
    $reservation->forceFill(['user_id' => null, 'contact_type' => ContactType::Guest])->save();

    visitReservationDetail($user, $reservation)->assertNotFound();
});

it('お支払い前・期限切れの予約は対象外とする（4.5.3「対象とする状態」）', function (ReservationStatus $status) {
    createCinema('gion', '祇園ムビ');
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user);
    $reservation->forceFill(['status' => $status])->save();

    visitReservationDetail($user, $reservation)->assertNotFound();
})->with([
    'pending' => [ReservationStatus::Pending],
    'expired' => [ReservationStatus::Expired],
]);

it('キャンセル済みには上映回の情報が変更されうる旨を注記する（12章 残課題37・40）', function () {
    createCinema('gion', '祇園ムビ');
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user, status: ReservationStatus::Cancelled);

    visitReservationDetail($user, $reservation)
        ->assertOk()
        ->assertSee(__('front.lookup.status.cancelled'))
        ->assertSee(__('front.lookup.cancelled_note'))
        // キャンセル済みには入場の案内もキャンセルの節も出さない（7.19-6・8）。
        ->assertDontSee(__('front.lookup.entry_pending'))
        ->assertDontSee(__('front.cancel.heading'));
});

/*
 * キャンセル（4.4 / 4.3.18）。会員の導線が P-07 と同じ `ReservationService::cancel()`
 * を呼ぶことと、**到達の根拠が所有者の一致であること**を固定する。
 */

it('会員の導線から確認を1段挟んでキャンセルできる（4.4「操作導線」/ 4.3.18）', function () {
    fakeStripeService(settledCharge(4000));
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user, startsAt: CarbonImmutable::now()->addDay());

    $component = Livewire::actingAs($user)
        ->test(ReservationDetail::class, ['reservationId' => $reservation->id])
        ->assertSee(__('front.cancel.start'))
        // 確認を出す前に実行しても何も起きない。
        ->call('cancel')
        ->assertDontSee(__('front.cancel.done_heading'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);

    $component->call('startCancel')
        ->assertSee(__('front.cancel.confirm_heading'))
        ->call('cancel')
        ->assertSee(__('front.cancel.done_heading'))
        ->assertSee(__('front.cancel.done'))
        // 成立後は導線を消し、明細も書き換わる（1つのコンポーネントが画面を描く）。
        ->assertDontSee(__('front.cancel.start'))
        ->assertSee(__('front.lookup.status.cancelled'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('他の会員はコンポーネントの内側でも予約を読めない（17.15 T-11）', function () {
    fakeStripeService(settledCharge(4000));
    $owner = User::factory()->create();
    $other = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($owner, startsAt: CarbonImmutable::now()->addDay());

    // **所有者の判定はコントローラではなくコンポーネントの内側にもある。** 予約IDを
    // 握っていても、別の会員のセッションでは読めない（4.5.4。P-07 の `matchedIds` には
    // 無い強さ。4.3.17「到達の根拠の限界」）。
    Livewire::actingAs($other)
        ->test(ReservationDetail::class, ['reservationId' => $reservation->id])
        ->assertStatus(404)
        ->assertDontSee(__('front.cancel.start'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);
});

it('導線を出していない予約は、確認を経ても実行時に拒む（4.3.18「条件の判定場所」）', function (ReservationStatus $status, ?CarbonImmutable $startsAt) {
    fakeStripeService(settledCharge(4000));
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user, status: $status, startsAt: $startsAt);

    // 画面にボタンは出ていないが、`startCancel` / `cancel` を直接呼ぶ経路を塞いでいる
    // ことを確かめる（判定はサーバー側でやり直す）。
    Livewire::actingAs($user)
        ->test(ReservationDetail::class, ['reservationId' => $reservation->id])
        ->assertDontSee(__('front.cancel.start'))
        ->call('startCancel')
        ->call('cancel');

    expect($reservation->refresh()->status)->toBe($status);
})->with([
    'キャンセル済み' => [ReservationStatus::Cancelled, null],
    '期限切れ' => [ReservationStatus::Paid, CarbonImmutable::now()->addMinutes(10)],
]);

it('確認をやめればキャンセルしない（4.3.18）', function () {
    fakeStripeService(settledCharge(4000));
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user, startsAt: CarbonImmutable::now()->addDay());

    Livewire::actingAs($user)
        ->test(ReservationDetail::class, ['reservationId' => $reservation->id])
        ->call('startCancel')
        ->call('abortCancel')
        ->assertDontSee(__('front.cancel.confirm_heading'))
        ->assertSee(__('front.cancel.start'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);
});

it('期限を過ぎた予約にはキャンセルの導線を出さない（4.4-1）', function () {
    createCinema('gion', '祇園ムビ');
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user, startsAt: CarbonImmutable::now()->addMinutes(10));

    visitReservationDetail($user, $reservation)
        ->assertOk()
        ->assertSee(__('front.cancel.unavailable.deadline'))
        ->assertDontSee(__('front.cancel.start'));
});

it('入場済みの予約にはキャンセルの導線を出さない（4.4-5）', function () {
    createCinema('gion', '祇園ムビ');
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user, startsAt: CarbonImmutable::now()->addDay());
    $reservation->forceFill(['checked_in_at' => CarbonImmutable::now()])->save();

    visitReservationDetail($user, $reservation)
        ->assertOk()
        ->assertSee(__('front.lookup.checked_in_note'))
        ->assertSee(__('front.cancel.unavailable.checked_in'))
        ->assertDontSee(__('front.cancel.start'));
});

it('返金に失敗した場合は成立と未了の双方を伝える（4.3.18）', function () {
    $stripe = fakeStripeService(settledCharge(4000));
    $stripe->refundError = StripeException::refundFailed();
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user, startsAt: CarbonImmutable::now()->addDay());

    Livewire::actingAs($user)
        ->test(ReservationDetail::class, ['reservationId' => $reservation->id])
        ->call('startCancel')
        ->call('cancel')
        ->assertSee(__('front.cancel.refund_pending_heading'))
        ->assertSee(__('front.cancel.refund_pending'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('画面に導線が出ていても、実行時に期限を過ぎていれば拒む（4.3.18）', function () {
    fakeStripeService(settledCharge(4000));
    $user = User::factory()->create();
    ['reservation' => $reservation, 'screening' => $screening] = detailReservation($user, startsAt: CarbonImmutable::now()->addHour());

    $component = Livewire::actingAs($user)
        ->test(ReservationDetail::class, ['reservationId' => $reservation->id])
        ->assertSee(__('front.cancel.start'))
        ->call('startCancel');

    // 確認を出した後に期限を過ぎる。判定はサーバー側でやり直す（4.3.18）。
    $screening->update(['starts_at' => CarbonImmutable::now()->addMinutes(10)]);

    $component->call('cancel')
        ->assertSee(__('front.cancel.errors.deadline_passed'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);
});

it('マイページの一覧から予約詳細へ遷移できる（4.5.3「P-06 への導線」）', function () {
    createCinema('gion', '祇園ムビ');
    $user = User::factory()->create();
    ['reservation' => $reservation] = detailReservation($user, startsAt: CarbonImmutable::now()->addDay());

    test()->actingAs($user)->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(route('front.mypage.reservation.show', ['id' => $reservation->id]), escape: false);
});
