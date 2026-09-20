<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Livewire\Front\Lookup\Index;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\TicketType;
use App\Models\User;
use App\Services\StripeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * 予約照会（P-07、4.3.5 / 7.19）。照合の条件・照会の対象・レート制限（17.2.2）と、
 * 照合を経ずに他人の予約へ到達できないこと（17.15 T-11）を固定する。
 *
 * 予約内容の表示項目そのものは tests/Feature/Front/ReservationCompleteTest.php と
 * 同じ部品（`x-front.reservation.screening-summary`）を使うため、ここでは照会の結果
 * 正しい予約が選ばれたことの確認に絞る。
 */

const LOOKUP_EMAIL = 'taro@example.test';
const LOOKUP_PHONE = '09012345678';

/**
 * 照会の対象となる予約を1件作る。
 *
 * **支払金額は席ごとの確定額の合計とする**（`ReservationService` が作るのはこの状態
 * だけである。4.3.15 / 6.5.4）。
 *
 * @return array{reservation: Reservation, screening: Screening, seats: array<int, Seat>}
 */
function lookupReservation(
    ReservationStatus $status = ReservationStatus::Paid,
    ?User $user = null,
    ?CarbonImmutable $startsAt = null,
    string $email = LOOKUP_EMAIL,
    string $phone = LOOKUP_PHONE,
    int $seatCount = 2,
    int $seatAmount = 2000,
): array {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture($seatCount);
    $ticketType = adultTicket($seatAmount);

    if ($startsAt !== null) {
        $screening->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2)]);
        $screening->refresh();
    }

    $isMember = $user !== null;

    $reservation = Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'user_id' => $user?->id,
        'guest_name' => $isMember ? null : '祇園　太郎',
        'guest_name_kana' => $isMember ? null : 'ギオン　タロウ',
        'contact_type' => $isMember ? ContactType::Member : ContactType::Guest,
        'guest_email' => $isMember ? null : $email,
        'guest_phone' => $isMember ? null : $phone,
        'screening_id' => $screening->id,
        'status' => $status,
        'total_amount' => $seatAmount * $seatCount,
    ]);

    // 確定済みの予約は PaymentIntent のIDを持つ（4.3.15）。キャンセルの返金先になるため
    // 省略しない。**一意制約があるため予約IDから組み立てる**（1テストで複数件作るケースがある）。
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

        // キャンセル済みは座席を占有しない（6.4.2）。`released_at` は解放の担当
        // （`ReservationService`）だけが書くため fillable に含まれない。
        if ($status === ReservationStatus::Cancelled) {
            $row->forceFill(['released_at' => CarbonImmutable::now()])->save();
        }
    }

    return ['reservation' => $reservation, 'screening' => $screening, 'seats' => $seats];
}

/** 方式A（予約番号＋メールアドレス）で照会する。 */
function lookupByNumber(string $reservationNo, string $email = LOOKUP_EMAIL): Testable
{
    return Livewire::test(Index::class)
        ->set('method', Index::METHOD_NUMBER)
        ->set('reservationNo', $reservationNo)
        ->set('email', $email)
        ->call('search');
}

beforeEach(function () {
    // レート制限（17.2.2）はIP単位のため、テスト間で持ち越すと後続が落ちる。
    RateLimiter::clear('lookup:m|127.0.0.1');
    RateLimiter::clear('lookup:h|127.0.0.1');
});

it('ページが照会フォームを表示し、クロール対象外となる（4.3.5 / 19.3-6）', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.lookup.index'))
        ->assertOk()
        ->assertSee(__('front.lookup.heading'))
        ->assertSee(__('front.lookup.method.number'))
        ->assertSee(__('front.lookup.method.contact'))
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('館が1件も存在しない場合は404を返す（他の館非依存ページと揃える）', function () {
    // ヘッダーの描画中に `firstOrFail()` が落ちると、ビューの例外に包まれて 500 になる。
    // P-08〜P-20（`PagePlaceholderController`）は 404 を返すため、揃えておく。
    $this->get(route('front.lookup.index'))->assertNotFound();
});

it('方式A（予約番号＋メールアドレス）で予約を表示する（4.3.5）', function () {
    ['reservation' => $reservation, 'seats' => $seats] = lookupReservation();

    lookupByNumber($reservation->reservation_no)
        ->assertSee($reservation->formattedReservationNo())
        ->assertSee('テスト作品')
        ->assertSee($seats[0]->displayName())
        ->assertSee(TicketType::ADULT_NAME)
        ->assertSee(__('front.lookup.status.paid'))
        // 入場用QRコード・領収書は未実装（12章 残課題18 / 36）。代替手段を案内する。
        ->assertSee(__('front.lookup.entry_pending'))
        ->assertSee(__('front.lookup.receipt_pending'));
});

it('予約番号はハイフン付きでも受け付ける（4.3.5「入力」）', function () {
    ['reservation' => $reservation] = lookupReservation();

    lookupByNumber($reservation->formattedReservationNo())
        ->assertSee($reservation->formattedReservationNo())
        ->assertSee(__('front.lookup.status.paid'));
});

it('メールアドレスが一致しなければ表示しない（4.3.5 方式A / 17.15 T-11）', function () {
    ['reservation' => $reservation] = lookupReservation();

    // 予約番号は8桁の数字であり推測できる。番号だけでは到達させない。
    lookupByNumber($reservation->reservation_no, 'other@example.test')
        ->assertSee(__('front.lookup.not_found'))
        ->assertDontSee($reservation->formattedReservationNo());
});

it('会員の予約は users の連絡先で照合する（4.3.5）', function () {
    $user = User::factory()->create(['email' => 'member@example.test', 'phone' => '0759876543']);
    ['reservation' => $reservation] = lookupReservation(user: $user);

    // 会員の予約は `guest_email` が null であり、`users.email` で引き当てる。
    lookupByNumber($reservation->reservation_no, 'member@example.test')
        ->assertSee($reservation->formattedReservationNo());
});

it('会員の予約もメールアドレスが一致しなければ表示しない（17.15 T-11）', function () {
    $user = User::factory()->create(['email' => 'member@example.test', 'phone' => '0759876543']);
    ['reservation' => $reservation] = lookupReservation(user: $user);

    // **会員側の枝を通る不一致の確認。** 非会員の不一致テストだけでは、会員側の
    // `whereIn('user_id', …)` からメールの条件が抜けても検出できず、「予約番号だけで
    // 他人（会員）の予約に到達できる」退行を見逃す。
    lookupByNumber($reservation->reservation_no, 'other@example.test')
        ->assertSee(__('front.lookup.not_found'))
        ->assertDontSee($reservation->formattedReservationNo());
});

it('同じ連絡先の非会員予約と会員予約を、UNION の両辺からまとめて拾う（4.3.5）', function () {
    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(19, 0);
    $user = User::factory()->create(['email' => LOOKUP_EMAIL, 'phone' => LOOKUP_PHONE]);

    ['reservation' => $guestSide] = lookupReservation(startsAt: $startsAt);
    ['reservation' => $memberSide] = lookupReservation(user: $user, startsAt: $startsAt->addHour());

    Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->assertSee($guestSide->formattedReservationNo())
        ->assertSee($memberSide->formattedReservationNo());
});

it('方式B（メール＋電話＋上映日）で予約を表示する（4.3.5）', function () {
    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(19, 0);
    ['reservation' => $reservation] = lookupReservation(startsAt: $startsAt);

    Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->assertSee($reservation->formattedReservationNo());
});

it('方式Bは電話番号が一致しなければ表示しない（4.3.5 / 17.15 T-11）', function () {
    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(19, 0);
    ['reservation' => $reservation] = lookupReservation(startsAt: $startsAt);

    Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', '09099999999')
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->assertSee(__('front.lookup.not_found'))
        ->assertDontSee($reservation->formattedReservationNo());
});

it('キャンセル済みの予約も照会でき、上映回が変わりうる旨を断る（4.3.16 / 12章 残課題37）', function () {
    ['reservation' => $reservation] = lookupReservation(status: ReservationStatus::Cancelled);

    lookupByNumber($reservation->reservation_no)
        ->assertSee(__('front.lookup.status.cancelled'))
        ->assertSee(__('front.lookup.cancelled_note'))
        // キャンセル済みに入場の案内は出さない。
        ->assertDontSee(__('front.lookup.entry_pending'));
});

it('お支払い前・期限切れの予約は照会の対象外とする（4.3.17）', function (ReservationStatus $status) {
    ['reservation' => $reservation] = lookupReservation(status: $status);

    lookupByNumber($reservation->reservation_no)
        ->assertSee(__('front.lookup.not_found'))
        ->assertDontSee($reservation->formattedReservationNo());
})->with([
    'pending' => [ReservationStatus::Pending],
    'expired' => [ReservationStatus::Expired],
]);

it('複数件が該当する場合は一覧を出し、選択で明細へ進む（4.3.5）', function () {
    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(19, 0);
    ['reservation' => $first] = lookupReservation(startsAt: $startsAt);
    ['reservation' => $second] = lookupReservation(startsAt: $startsAt->addHour());

    $component = Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->assertSee(__('front.lookup.candidates_heading'))
        // 上映開始の新しい順に並べる（4.3.17「一覧の並び順」）。`UNION` は並び順を
        // 保証しないため、束ねた後の並べ直しがここでしか担保されない。
        ->assertSeeInOrder([$second->formattedReservationNo(), $first->formattedReservationNo()]);

    $component->call('select', $second->id)
        ->assertSee(__('front.lookup.detail_heading'))
        ->assertSee($second->formattedReservationNo());
});

it('照合の範囲に無い予約IDを指定しても開けない（17.15 T-11）', function () {
    ['reservation' => $mine] = lookupReservation();
    ['reservation' => $other] = lookupReservation(email: 'other@example.test');

    // 自分の予約で照合を済ませたうえで、他人の予約IDを送る。`matchedIds` に無いため
    // 選択されず、明細は自分の予約のままになる。
    lookupByNumber($mine->reservation_no)
        ->call('select', $other->id)
        ->assertSee($mine->formattedReservationNo())
        ->assertDontSee($other->formattedReservationNo());
});

it('照合を経ずに予約IDを送っても開けない（17.15 T-11）', function () {
    ['reservation' => $reservation] = lookupReservation();

    Livewire::test(Index::class)
        ->call('select', $reservation->id)
        ->assertDontSee($reservation->formattedReservationNo());
});

it('入力の形式を検証する（17.5.1-5）', function () {
    Livewire::test(Index::class)
        ->set('method', Index::METHOD_NUMBER)
        ->set('reservationNo', '123')
        ->set('email', 'not-an-email')
        ->call('search')
        ->assertHasErrors(['reservationNo', 'email']);
});

it('1分あたりの照会回数を制限する（17.2.2 / 17.15 T-15）', function () {
    ['reservation' => $reservation] = lookupReservation();

    // 上限は5回。6回目で待機を求める。
    foreach (range(1, 5) as $ignored) {
        lookupByNumber($reservation->reservation_no, 'other@example.test');
    }

    lookupByNumber($reservation->reservation_no)
        ->assertHasErrors('email')
        // 正しい入力であっても、制限に達していれば照合しない。
        ->assertDontSee($reservation->formattedReservationNo());
});

it('形式の誤った入力も制限の回数に数える（17.2.2）', function () {
    ['reservation' => $reservation] = lookupReservation();

    // 検証を制限より先に置くと、形式の誤りを無制限に試せてしまう。
    foreach (range(1, 5) as $ignored) {
        Livewire::test(Index::class)
            ->set('method', Index::METHOD_NUMBER)
            ->set('reservationNo', $reservation->reservation_no)
            ->set('email', 'not-an-email')
            ->call('search')
            ->assertHasErrors('email');
    }

    lookupByNumber($reservation->reservation_no)
        ->assertDontSee($reservation->formattedReservationNo());
});

it('方式Bは会員の users.phone でも照合する（4.3.5 / 6.1 UNION の前提）', function () {
    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(19, 0);
    $user = User::factory()->create(['email' => 'member@example.test', 'phone' => '0759876543']);
    ['reservation' => $reservation] = lookupReservation(user: $user, startsAt: $startsAt);

    // 会員側は `whereIn('user_id', ...)` の枝で引き当てる。電話の条件がこの枝から
    // 抜けても、方式A（予約番号）のテストでは検出できない。
    Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', 'member@example.test')
        ->set('phone', '0759876543')
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->assertSee($reservation->formattedReservationNo());
});

it('方式Bは会員の電話番号が一致しなければ表示しない（17.15 T-11）', function () {
    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(19, 0);
    $user = User::factory()->create(['email' => 'member@example.test', 'phone' => '0759876543']);
    ['reservation' => $reservation] = lookupReservation(user: $user, startsAt: $startsAt);

    Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', 'member@example.test')
        ->set('phone', '0700000000')
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->assertSee(__('front.lookup.not_found'))
        ->assertDontSee($reservation->formattedReservationNo());
});

it('照会方式を切り替えた後、前の方式の入力で行き詰まらない（4.3.17）', function () {
    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(19, 0);
    ['reservation' => $reservation] = lookupReservation(startsAt: $startsAt);

    // 方式Aで予約番号を途中まで入れて失敗 → 方式Bへ切り替える。方式Bの画面に予約番号の
    // 入力欄は無いため、その値の誤りを指摘すると利用者は消す手段を持たない。
    Livewire::test(Index::class)
        ->set('method', Index::METHOD_NUMBER)
        ->set('reservationNo', '123')
        ->set('email', LOOKUP_EMAIL)
        ->call('search')
        ->assertHasErrors('reservationNo')
        ->set('method', Index::METHOD_CONTACT)
        // 方式の切り替えで前の誤りの表示を消す。
        ->assertHasNoErrors()
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee($reservation->formattedReservationNo());
});

it('1時間の枠は1時間で減衰する（17.2.2）', function () {
    ['reservation' => $reservation] = lookupReservation();

    // **枠が空いている状態で1回だけ照会し、コンポーネントが積んだタイマーを見る。**
    // 先に上限まで積むと `ensureIsNotRateLimited()` で例外になり `hit()` へ到達しない
    // ため、減衰秒数の指定（3600）が検証されない。
    lookupByNumber($reservation->reservation_no)
        ->assertHasNoErrors();

    expect(RateLimiter::availableIn('lookup:h|127.0.0.1'))->toBeGreaterThan(60)
        ->and(RateLimiter::availableIn('lookup:m|127.0.0.1'))->toBeLessThanOrEqual(60);
});

it('1時間あたりの照会回数を制限する（17.2.2 / 17.15 T-15）', function () {
    ['reservation' => $reservation] = lookupReservation();

    // 1分の枠（5回）に触れずに1時間の枠（20回）だけを埋める。
    RateLimiter::increment('lookup:h|127.0.0.1', 3600, 20);

    lookupByNumber($reservation->reservation_no)
        ->assertHasErrors('email')
        ->assertDontSee($reservation->formattedReservationNo());
});

it('上映日はその日の 00:00 から翌日 00:00 の手前までで切る（4.3.17）', function (int $hour, bool $expected) {
    $date = CarbonImmutable::now()->addDays(3)->startOfDay();
    ['reservation' => $reservation] = lookupReservation(startsAt: $date->addHours($hour));

    $component = Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $date->format('Y-m-d'))
        ->call('search');

    $expected
        ? $component->assertSee($reservation->formattedReservationNo())
        : $component->assertDontSee($reservation->formattedReservationNo());
})->with([
    '当日 00:00 は含む' => [0, true],
    '当日 23:00 は含む' => [23, true],
    '翌日 00:00 は含まない' => [24, false],
]);

it('一覧へ戻る・条件を変えて照会するの操作で状態が戻る（7.19）', function () {
    $startsAt = CarbonImmutable::now()->addDays(3)->setTime(19, 0);
    ['reservation' => $first] = lookupReservation(startsAt: $startsAt);
    lookupReservation(startsAt: $startsAt->addHour());

    $component = Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->call('select', $first->id)
        ->assertSee(__('front.lookup.detail_heading'));

    $component->call('backToList')
        ->assertSee(__('front.lookup.candidates_heading'))
        ->assertDontSee(__('front.lookup.detail_heading'));

    $component->call('startOver')
        ->assertSee(__('front.lookup.method_legend'))
        ->assertDontSee(__('front.lookup.candidates_heading'))
        // 照合の根拠を捨てるため、以後は選択もできない。
        ->call('select', $first->id)
        ->assertDontSee($first->formattedReservationNo());
});

it('予約確定メールのリンクから開くと予約番号が埋まる（4.3.5）', function () {
    createCinema('gion', '祇園ムビ');
    ['reservation' => $reservation] = lookupReservation();

    // メールアドレスは載せない。転送や履歴から照合の2要素が揃わないようにする。
    $this->get(route('front.lookup.index', ['no' => $reservation->formattedReservationNo()]))
        ->assertOk()
        ->assertSee($reservation->formattedReservationNo());
});

/*
 * キャンセル（4.4 / 7.19-8 / 4.3.18）。**画面の導線と到達の可否**を固定する。
 * 判定・座席の解放・返金そのものは tests/Feature/Reservation/ReservationCancelTest.php が担保する。
 */

it('キャンセルは確認を1段挟んでから実行する（4.3.18）', function () {
    fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation] = lookupReservation(startsAt: CarbonImmutable::now()->addDay());

    $component = lookupByNumber($reservation->reservation_no)
        ->assertSee(__('front.cancel.start'))
        // 確認を出す前に実行しても何も起きない。
        ->call('cancel')
        ->assertDontSee(__('front.cancel.done_heading'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);

    $component->call('startCancel')
        ->assertSee(__('front.cancel.confirm_heading'))
        ->call('cancel')
        ->assertSee(__('front.cancel.done_heading'))
        ->assertSee(__('front.cancel.done'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('確認をやめればキャンセルしない（7.19-8）', function () {
    fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation] = lookupReservation(startsAt: CarbonImmutable::now()->addDay());

    lookupByNumber($reservation->reservation_no)
        ->call('startCancel')
        ->call('abortCancel')
        ->assertDontSee(__('front.cancel.confirm_heading'))
        ->assertSee(__('front.cancel.start'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);
});

it('照合を経ていなければキャンセルできない（17.15 T-11）', function () {
    fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation] = lookupReservation(startsAt: CarbonImmutable::now()->addDay());

    // 照合を経ずに confirmingCancel を立てる経路は無い（#[Locked]）。
    Livewire::test(Index::class)
        ->call('startCancel')
        ->call('cancel');

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);
});

it('期限を過ぎた予約にはキャンセルの導線を出さない（4.4-1）', function () {
    ['reservation' => $reservation] = lookupReservation(startsAt: CarbonImmutable::now()->addMinutes(10));

    lookupByNumber($reservation->reservation_no)
        ->assertSee(__('front.cancel.unavailable.deadline'))
        ->assertDontSee(__('front.cancel.start'));
});

it('入場済みの予約にはキャンセルの導線を出さない（4.4-5）', function () {
    ['reservation' => $reservation] = lookupReservation(startsAt: CarbonImmutable::now()->addDay());
    $reservation->forceFill(['checked_in_at' => CarbonImmutable::now()])->save();

    lookupByNumber($reservation->reservation_no)
        ->assertSee(__('front.cancel.unavailable.checked_in'))
        ->assertDontSee(__('front.cancel.start'));
});

it('キャンセル済みの予約にはキャンセルの節そのものを出さない（7.19-8）', function () {
    ['reservation' => $reservation] = lookupReservation(
        status: ReservationStatus::Cancelled,
        startsAt: CarbonImmutable::now()->addDay(),
    );

    lookupByNumber($reservation->reservation_no)
        ->assertSee(__('front.lookup.status.cancelled'))
        ->assertDontSee(__('front.cancel.heading'))
        ->assertDontSee(__('front.cancel.start'));
});

it('返金に失敗した場合は成立と未了の双方を伝える（4.3.18）', function () {
    $stripe = fakeStripeService(settledCharge(4000));
    $stripe->refundError = StripeException::refundFailed();
    ['reservation' => $reservation] = lookupReservation(startsAt: CarbonImmutable::now()->addDay());

    lookupByNumber($reservation->reservation_no)
        ->call('startCancel')
        ->call('cancel')
        ->assertSee(__('front.cancel.done_heading'))
        ->assertSee(__('front.cancel.refund_pending'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('画面に導線が出ていても、実行時に期限を過ぎていれば拒む（4.3.18）', function () {
    fakeStripeService(settledCharge(4000));
    $startsAt = CarbonImmutable::now()->addHour();
    ['reservation' => $reservation] = lookupReservation(startsAt: $startsAt);

    $component = lookupByNumber($reservation->reservation_no)
        ->assertSee(__('front.cancel.start'))
        ->call('startCancel');

    // 確認を出してからボタンを押すまでの間に期限を過ぎた。画面側の判定は信用しない。
    CarbonImmutable::setTestNow($startsAt->subMinutes(5));

    $component->call('cancel')
        ->assertSee(__('front.cancel.errors.deadline_passed'))
        ->assertDontSee(__('front.cancel.done_heading'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Paid);

    CarbonImmutable::setTestNow();
});

it('キャンセルの完了案内が、別の予約を開いたときに持ち越されない（4.3.18）', function () {
    fakeStripeService(settledCharge(4000));
    // 同じ「上映日」で2件を照会するため、日内に収める（23時台の実行で翌日へ転ばせない）。
    $startsAt = CarbonImmutable::now()->addDay()->startOfDay()->addHours(10);
    ['reservation' => $first] = lookupReservation(startsAt: $startsAt);
    ['reservation' => $second] = lookupReservation(startsAt: $startsAt->addHour());

    $component = Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->call('select', $first->id)
        ->call('startCancel')
        ->call('cancel')
        ->assertSee(__('front.cancel.done_heading'));

    // **まだ `paid` の予約に「返金いたします」と案内してはならない。** あわせて、
    // その予約本来のキャンセル導線が消えてもいけない。
    $component->call('backToList')
        ->call('select', $second->id)
        ->assertDontSee(__('front.cancel.done_heading'))
        ->assertSee(__('front.cancel.start'));

    expect($second->refresh()->status)->toBe(ReservationStatus::Paid);
});

it('確認を出したまま別の予約へ移っても、確認を経ずにキャンセルできない（4.3.18）', function () {
    fakeStripeService(settledCharge(4000));
    // 同上。日跨ぎで照合が1件になると、意図と違う経路を検証することになる。
    $startsAt = CarbonImmutable::now()->addDay()->startOfDay()->addHours(10);
    ['reservation' => $first] = lookupReservation(startsAt: $startsAt);
    ['reservation' => $second] = lookupReservation(startsAt: $startsAt->addHour());

    $component = Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->call('select', $first->id)
        ->call('startCancel')
        ->assertSee(__('front.cancel.confirm_heading'));

    // 確定せずに別の予約を開く。確認は引き継がれない。
    $component->call('backToList')
        ->call('select', $second->id)
        ->assertDontSee(__('front.cancel.confirm_heading'))
        ->assertSee(__('front.cancel.start'))
        ->call('cancel')
        ->assertDontSee(__('front.cancel.done_heading'));

    expect($second->refresh()->status)->toBe(ReservationStatus::Paid)
        ->and($first->refresh()->status)->toBe(ReservationStatus::Paid);
});

it('返金先が分からない予約はキャンセルを成立させたうえで未了を伝える（4.3.18）', function () {
    $stripe = fakeStripeService(settledCharge(4000));
    ['reservation' => $reservation] = lookupReservation(startsAt: CarbonImmutable::now()->addDay());
    $reservation->forceFill(['stripe_payment_intent_id' => null])->save();

    lookupByNumber($reservation->reservation_no)
        ->call('startCancel')
        ->call('cancel')
        ->assertSee(__('front.cancel.refund_pending'));

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled)
        // 返金先が無いため Refund API を呼びようがない。
        ->and($stripe->refunds)->toBe([]);
});

it('支払金額0円の予約は返金に触れない案内を出す（4.5.2）', function () {
    fakeStripeService(settledCharge(0));
    ['reservation' => $reservation] = lookupReservation(startsAt: CarbonImmutable::now()->addDay(), seatAmount: 0);

    lookupByNumber($reservation->reservation_no)
        ->call('startCancel')
        ->call('cancel')
        ->assertSee(__('front.cancel.done_no_refund'))
        ->assertDontSee(__('front.cancel.done'));
});

it('キャンセルを承れなかった理由も、別の予約へ持ち越されない（4.3.18）', function () {
    fakeStripeService(settledCharge(4000));
    // 同じ「上映日」で2件を照会するため日内に収める。1件目は入場済みでキャンセル不可。
    $startsAt = CarbonImmutable::now()->addDay()->startOfDay()->addHours(10);
    ['reservation' => $first] = lookupReservation(startsAt: $startsAt);
    ['reservation' => $second] = lookupReservation(startsAt: $startsAt->addHour());

    $component = Livewire::test(Index::class)
        ->set('method', Index::METHOD_CONTACT)
        ->set('email', LOOKUP_EMAIL)
        ->set('phone', LOOKUP_PHONE)
        ->set('screeningDate', $startsAt->format('Y-m-d'))
        ->call('search')
        ->call('select', $first->id)
        ->call('startCancel');

    // 確認を出した後、実行までの間に入場された。サーバー側の判定で拒否される。
    $first->forceFill(['checked_in_at' => CarbonImmutable::now()])->save();

    $component->call('cancel')
        ->assertSee(__('front.cancel.errors.checked_in'));

    // 別の予約を開いたとき、前の予約の理由が残らない。
    $component->call('backToList')
        ->call('select', $second->id)
        ->assertDontSee(__('front.cancel.errors.checked_in'))
        ->assertSee(__('front.cancel.start'));
});
