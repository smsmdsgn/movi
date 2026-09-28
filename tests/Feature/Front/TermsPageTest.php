<?php

use App\Models\Screening;
use App\Models\User;

/*
 * 利用規約（P-16、7.1.1 / 4.3.7 / 4.4 / 4.9.1。工程7-f）。
 *
 * オンラインチケット購入の条項（id="online-ticket"）は、同意画面（P-32）と同じ
 * 3つの文言（no_change・cancel_deadline・late_entry）をそのまま参照し、
 * 4.4 の条件（手数料なし・全額返金・座席の一部のみのキャンセル不可・
 * 入場済みの予約はキャンセル不可）を含む。
 */
it('id="online-ticket"の条項に同意画面と同じ文言と4.4の条件を含む', function () {
    createCinema('gion', '祇園ムビ');

    $html = $this->get(route('front.terms.index'))->assertOk()->getContent();

    expect($html)->toContain('id="online-ticket"');
    expect($html)->toContain(__('front.reservation.agreement.terms.no_change'));
    expect($html)->toContain(__('front.reservation.agreement.terms.cancel_deadline', ['minutes' => Screening::CANCEL_DEADLINE_MINUTES]));
    expect($html)->toContain(__('front.reservation.agreement.terms.late_entry'));
    expect($html)->toContain(__('front.pages.terms.no_fee_full_refund'));
    expect($html)->toContain(__('front.pages.terms.no_partial_cancel'));
    expect($html)->toContain(__('front.pages.terms.no_cancel_after_checkin'));
    expect($html)->not->toContain(':minutes');
});

it('同意画面の「利用規約の全文を読む」リンク先は#online-ticket付きの利用規約ページである', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    $user = User::factory()->create();
    holdSeatsForScreening($screening, 'user:'.$user->id, $seats[0]);

    $this->actingAs($user)
        ->get(route('front.reservation.agreement', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('href="'.route('front.terms.index').'#online-ticket"', false);
});
