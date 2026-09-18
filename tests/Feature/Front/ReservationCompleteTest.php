<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\TicketType;
use App\Models\User;

/*
 * 予約完了（P-38、7.13）。表示項目と、到達の可否（所有者の判定、17.2.1 / 12章 旧残課題34）を
 * 固定する。
 *
 * 確定そのものは tests/Feature/Reservation/ReservationServiceTest.php、確定後の遷移は
 * tests/Feature/Front/ConfirmTest.php が担保する。
 */

/**
 * 確定済み（`paid`）の予約を、座席・券種つきで1件作る。
 *
 * **支払金額は席ごとの確定額の合計とする。** `ReservationService` が作るのはこの状態
 * だけであり（`total_amount` = Σ`t_reservation_seats.amount`。4.3.15 / 6.5.4）、
 * 食い違う値を組み立てられるようにすると、実在しないデータで通るテストになる。
 *
 * @return array{reservation: Reservation, screening: Screening, seats: array<int, Seat>, ticketType: TicketType}
 */
function completedReservation(
    int $seatCount = 2,
    int $seatAmount = 2000,
    ReservationStatus $status = ReservationStatus::Paid,
    ?User $user = null,
): array {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture($seatCount);
    $ticketType = adultTicket($seatAmount);

    $reservation = Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'user_id' => $user?->id,
        'guest_name' => $user === null ? '祇園　太郎' : null,
        'guest_name_kana' => $user === null ? 'ギオン　タロウ' : null,
        'contact_type' => $user === null ? ContactType::Guest : ContactType::Member,
        'guest_email' => $user === null ? 'guest@example.test' : null,
        'guest_phone' => $user === null ? '0751234567' : null,
        'screening_id' => $screening->id,
        'status' => $status,
        'total_amount' => $seatAmount * $seatCount,
    ]);

    foreach ($seats as $seat) {
        ReservationSeat::create([
            'reservation_id' => $reservation->id,
            'screening_id' => $screening->id,
            'seat_id' => $seat->id,
            'ticket_type_id' => $ticketType->id,
            'amount' => $seatAmount,
        ]);
    }

    return ['reservation' => $reservation, 'screening' => $screening, 'seats' => $seats, 'ticketType' => $ticketType];
}

it('確定させたブラウザに予約番号・予約内容・導線を表示し、クロール対象外となる（7.13 / 19.3-6）', function () {
    ['reservation' => $reservation, 'screening' => $screening, 'seats' => $seats] = completedReservation();

    $this->withSession(completedReservationSession($reservation))
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertOk()
        // 7.13-1 予約番号（4桁区切り）
        ->assertSee($reservation->formattedReservationNo())
        // 7.13-3 予約内容
        ->assertSee('テスト作品')
        ->assertSee($screening->theater->name)
        ->assertSee($seats[0]->displayName())
        ->assertSee($seats[1]->displayName())
        ->assertSee(TicketType::ADULT_NAME)
        ->assertSee(__('front.reservation.yen', ['amount' => '4,000']))
        // 7.13-2 / 7.13-4 / 7.13-5 は未実装（12章 残課題36）。代替手段を案内する
        ->assertSee(__('front.reservation.complete.entry_pending'))
        // 7.13-6 予約照会への導線
        ->assertSee(route('front.lookup.index'))
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('割引は席ごとの確定額に反映済みとして表示する（6.5.4 / 4.3.16）', function () {
    // ペア割は席ごとに確定させてから合計する（6.5.4）。大人2,000円の2席に500円ずつ
    // 配分された状態を作る。**小計・割引額は保存していないため表示しない**（残課題36）。
    ['reservation' => $reservation] = completedReservation(seatCount: 2, seatAmount: 1500);

    $this->withSession(completedReservationSession($reservation))
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertOk()
        // 席ごとの確定額と、その合計である支払金額
        ->assertSee(__('front.reservation.yen', ['amount' => '1,500']))
        ->assertSee(__('front.reservation.yen', ['amount' => '3,000']))
        ->assertSee(__('front.reservation.complete.amount_note'))
        // 割引前の金額（6.5.4 の「小計」）は保存しておらず、画面にも出さない
        ->assertDontSee(__('front.reservation.tickets.subtotal'));
});

it('保存済みの確定額のみを表示し、料金マスタを引き直さない（6.5.5）', function () {
    ['reservation' => $reservation, 'ticketType' => $ticketType] = completedReservation(seatCount: 1, seatAmount: 1800);

    // 確定後に料金が改定されても、予約の金額は動かない。
    $ticketType->update(['price' => 2200]);

    $this->withSession(completedReservationSession($reservation))
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertOk()
        ->assertSee(__('front.reservation.yen', ['amount' => '1,800']))
        ->assertDontSee(__('front.reservation.yen', ['amount' => '2,200']));
});

it('非会員の予約は、確定させたブラウザのセッションが無ければ404となる（12章 旧残課題34）', function () {
    ['reservation' => $reservation] = completedReservation();

    // 予約番号は8桁の数字であり推測できる（4.3.5）。第三者のブラウザからは到達させない。
    $this->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertNotFound();
});

it('会員は自身の予約であればセッションが無くても表示できる（17.2.1-1）', function () {
    $user = User::factory()->create();
    ['reservation' => $reservation] = completedReservation(user: $user);

    $this->actingAs($user)
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertOk()
        ->assertSee($reservation->formattedReservationNo())
        // 7.13-6 会員にはマイページへの導線も出す
        ->assertSee(route('front.mypage.index'));
});

it('他の会員の予約はログインしていても404となる（17.2.1-2）', function () {
    ['reservation' => $reservation] = completedReservation(user: User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertNotFound();
});

it('存在しない予約番号は404となる（他人の予約と区別しない。17.2.2）', function () {
    createCinema();

    $this->get(route('front.reservation.complete', ['no' => '99999999']))
        ->assertNotFound();
});

it('確定前・終端の予約は完了画面の対象としない（4.3.3）', function (ReservationStatus $status) {
    ['reservation' => $reservation] = completedReservation(status: $status);

    $this->withSession(completedReservationSession($reservation))
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertNotFound();
})->with([
    'pending（課金の直前に作られた行）' => ReservationStatus::Pending,
    'expired（期限切れ）' => ReservationStatus::Expired,
    'cancelled（キャンセル済み）' => ReservationStatus::Cancelled,
]);

it('非会員にはマイページへの導線を出さない（7.14 は会員専用）', function () {
    ['reservation' => $reservation] = completedReservation();

    $this->withSession(completedReservationSession($reservation))
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertOk()
        ->assertDontSee(route('front.mypage.index'));
});

it('会員の予約でも、確定させたブラウザであればログインせずに表示できる（4.3.16）', function () {
    ['reservation' => $reservation] = completedReservation(user: User::factory()->create());

    // Policy は「所有者の一致」と「確定させたブラウザ」のいずれかで通す。
    $this->withSession(completedReservationSession($reservation))
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertOk()
        ->assertSee($reservation->formattedReservationNo());
});

it('記録の上限を超えた予約は到達できなくなる（CompletedReservations の MAX_ENTRIES）', function () {
    ['reservation' => $oldest] = completedReservation(seatCount: 1);
    $session = completedReservationSession($oldest);

    // 以後5件を確定させると、最初の予約は記録から押し出される。
    foreach (range(1, 5) as $ignored) {
        ['reservation' => $newer] = completedReservation(seatCount: 1);
        $session = completedReservationSession($newer);
    }

    $this->withSession($session)
        ->get(route('front.reservation.complete', ['no' => $oldest->reservation_no]))
        ->assertNotFound();

    // 直近の予約には到達できる。
    $this->withSession($session)
        ->get(route('front.reservation.complete', ['no' => $newer->reservation_no]))
        ->assertOk();
});

it('ヘッダー・パンくずの館は予約から定まる（4.3.9 / CurrentCinemaService）', function () {
    ['reservation' => $reservation, 'screening' => $screening] = completedReservation();
    $other = createCinema('other-cinema', 'ムビ別館');
    $cinema = $screening->booking->cinema;

    // 直前に別の館を見ていても、完了画面は予約の館を現在の館として確定させる
    // （館名はヘッダーの館切替にも並ぶため、確定の事実はセッションで見る）。
    $this->withSession(completedReservationSession($reservation))
        ->withSession([Cinema::SESSION_KEY => $other->slug])
        ->get(route('front.reservation.complete', ['no' => $reservation->reservation_no]))
        ->assertOk()
        ->assertSee($cinema->name)
        ->assertSessionHas(Cinema::SESSION_KEY, $cinema->slug);
});

it('8桁の数字以外の予約番号はルートに一致しない（4.3.5）', function (string $no) {
    createCinema();

    $this->get("/reservations/{$no}/complete")->assertNotFound();
})->with([
    '7桁' => '1234567',
    '9桁' => '123456789',
    'ハイフン区切り' => '1234-5678',
]);
