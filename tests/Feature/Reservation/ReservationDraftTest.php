<?php

use App\Services\ReservationDraft;
use Illuminate\Support\Facades\Session;

/*
 * 予約フローが画面をまたいで持ち越す入力（13.4.7 / 4.3.12 / 4.3.13）。
 *
 * セッションの中身は本クラスだけが書くが、`SESSION_LIFETIME` をまたいだ再開や
 * 構造変更で古い形式が残りうる。**キーの欠落は `ErrorException` になって500を返す**ため、
 * 読み出し時の形式検証がその防御として働くことを固定する。
 */

function draft(): ReservationDraft
{
    return app(ReservationDraft::class);
}

it('同意と券種を記録し、同じ上映回で読み出せる', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    $adult = adultTicket();

    draft()->agree($screening);
    draft()->putTickets($screening, [$seats[0]->id => $adult->id]);

    expect(draft()->hasAgreed($screening->id))->toBeTrue()
        ->and(draft()->tickets($screening->id))->toBe([$seats[0]->id => $adult->id]);
});

it('別の上映回を記録すると前の回の内容を丸ごと捨てる（4.3.12）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();
    $adult = adultTicket();

    draft()->agree($screening);
    draft()->putTickets($screening, [$seats[0]->id => $adult->id]);
    draft()->putGuest($screening, ['name' => '祇園　太郎', 'name_kana' => 'ギオン　タロウ', 'phone' => '09012345678', 'email' => 'taro@example.com']);

    $other = createScreeningForTheater($theater);
    draft()->agree($other);

    // 別の回に同意した時点で、前の回の同意・入力・券種はすべて消える。
    expect(draft()->hasAgreed($screening->id))->toBeFalse()
        ->and(draft()->tickets($screening->id))->toBe([])
        ->and(draft()->guest($screening->id))->toBeNull()
        ->and(draft()->hasAgreed($other->id))->toBeTrue();
});

it('記録が無ければ空を返す', function () {
    ['screening' => $screening] = makeReservationFixture();

    expect(draft()->hasAgreed($screening->id))->toBeFalse()
        ->and(draft()->tickets($screening->id))->toBe([])
        ->and(draft()->guest($screening->id))->toBeNull();
});

it('形式が想定と異なるセッションは記録なしとして扱う（500にしない）', function (mixed $stored) {
    ['screening' => $screening] = makeReservationFixture();

    Session::put('reservation', $stored);

    expect(draft()->hasAgreed($screening->id))->toBeFalse()
        ->and(draft()->tickets($screening->id))->toBe([])
        ->and(draft()->guest($screening->id))->toBeNull();
})->with([
    '配列でない' => ['壊れた値'],
    '上映回IDが無い' => [['agreed_at' => null, 'guest' => null, 'tickets' => null, 'payment_method_id' => null]],
    '上映回IDが文字列' => [['screening_id' => '1', 'agreed_at' => null, 'guest' => null, 'tickets' => null, 'payment_method_id' => null]],
    // 工程5-i より前に作られたセッション（`tickets` キーを持たない）。
    '旧形式（ticketsキーが無い）' => [['screening_id' => 1, 'agreed_at' => '2026-09-17T00:00:00+09:00', 'guest' => null]],
    // 工程5-k より前に作られたセッション（`payment_method_id` キーを持たない）。
    '旧形式（payment_method_idキーが無い）' => [['screening_id' => 1, 'agreed_at' => '2026-09-17T00:00:00+09:00', 'guest' => null, 'tickets' => null]],
    'payment_method_id が文字列でない' => [['screening_id' => 1, 'agreed_at' => null, 'guest' => null, 'tickets' => null, 'payment_method_id' => 123]],
    'agreed_at が文字列でない' => [['screening_id' => 1, 'agreed_at' => 12345, 'guest' => null, 'tickets' => null, 'payment_method_id' => null]],
    'guest の項目が欠けている' => [['screening_id' => 1, 'agreed_at' => null, 'guest' => ['name' => '祇園'], 'tickets' => null, 'payment_method_id' => null]],
    'guest の値が文字列でない' => [['screening_id' => 1, 'agreed_at' => null, 'guest' => ['name' => 1, 'name_kana' => 1, 'phone' => 1, 'email' => 1], 'tickets' => null, 'payment_method_id' => null]],
    'tickets のキーが文字列' => [['screening_id' => 1, 'agreed_at' => null, 'guest' => null, 'tickets' => ['a' => 1], 'payment_method_id' => null]],
    'tickets の値が文字列' => [['screening_id' => 1, 'agreed_at' => null, 'guest' => null, 'tickets' => [1 => 'adult'], 'payment_method_id' => null]],
    'tickets が配列でない' => [['screening_id' => 1, 'agreed_at' => null, 'guest' => null, 'tickets' => 'adult', 'payment_method_id' => null]],
]);

it('空の券種の割り当ては正しい形式として受け付ける', function () {
    ['screening' => $screening] = makeReservationFixture();

    Session::put('reservation', ['screening_id' => $screening->id, 'agreed_at' => '2026-09-17T00:00:00+09:00', 'guest' => null, 'tickets' => [], 'payment_method_id' => null]);

    expect(draft()->hasAgreed($screening->id))->toBeTrue()
        ->and(draft()->tickets($screening->id))->toBe([]);
});

it('同意・お客様情報・券種・支払方法は互いを消さない', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    $adult = adultTicket();
    $guest = ['name' => '祇園　太郎', 'name_kana' => 'ギオン　タロウ', 'phone' => '09012345678', 'email' => 'taro@example.com'];

    // 予約フローの順（P-32 → P-34 → P-35 → P-36）で記録する。券種の記録は支払方法を
    // 捨てるため、この順序でのみ4つが揃う。
    draft()->agree($screening);
    draft()->putGuest($screening, $guest);
    draft()->putTickets($screening, [$seats[0]->id => $adult->id]);
    draft()->putPaymentMethod($screening, 'pm_card_visa');

    expect(draft()->hasAgreed($screening->id))->toBeTrue()
        ->and(draft()->guest($screening->id))->toBe($guest)
        ->and(draft()->tickets($screening->id))->toBe([$seats[0]->id => $adult->id])
        ->and(draft()->paymentMethodId($screening->id))->toBe('pm_card_visa');
});

it('券種を選び直すと支払方法を捨てる（P-35 へ戻った場合）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    $adult = adultTicket();

    draft()->agree($screening);
    draft()->putTickets($screening, [$seats[0]->id => $adult->id]);
    draft()->putPaymentMethod($screening, 'pm_card_visa');

    // 券種が変われば支払金額も変わる。前の内容のために用意したカードを持ち越さない。
    draft()->putTickets($screening, [$seats[0]->id => $adult->id, $seats[1]->id => $adult->id]);

    expect(draft()->paymentMethodId($screening->id))->toBeNull()
        ->and(draft()->hasAgreed($screening->id))->toBeTrue();
});

it('別の上映回の支払方法は読み出さない（4.3.12）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();

    draft()->putPaymentMethod($screening, 'pm_card_visa');

    $other = createScreeningForTheater($theater);

    expect(draft()->paymentMethodId($other->id))->toBeNull();
});
