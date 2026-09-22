<?php

use App\Enums\AdminRole;
use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\FreeTicket;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\Stamp;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * マイページ（P-05、7.14 / 4.5.3）。スタンプ数・無料鑑賞券・予約の振り分けと、
 * 他の会員の予約が混ざらないことを固定する。
 *
 * 予約のキャンセルは P-06（工程6-b）が担う。ここでは一覧の表示に絞る。
 */

/**
 * 会員1件ぶんの予約を作る。
 *
 * @return array{reservation: Reservation, screening: Screening, seats: array<int, Seat>}
 */
function mypageReservation(
    User $user,
    ?CarbonImmutable $startsAt = null,
    ReservationStatus $status = ReservationStatus::Paid,
    int $seatCount = 2,
    int $seatAmount = 2000,
): array {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture($seatCount);

    if ($startsAt !== null) {
        $screening->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2)]);
        $screening->refresh();
    }

    $ticketType = adultTicket($seatAmount);

    $reservation = Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'user_id' => $user->id,
        'contact_type' => ContactType::Member,
        'screening_id' => $screening->id,
        'status' => $status,
        'total_amount' => $seatAmount * $seatCount,
    ]);

    foreach ($seats as $seat) {
        $row = ReservationSeat::create([
            'reservation_id' => $reservation->id,
            'screening_id' => $screening->id,
            'seat_id' => $seat->id,
            'ticket_type_id' => $ticketType->id,
            'amount' => $seatAmount,
        ]);

        if ($status === ReservationStatus::Cancelled) {
            $row->forceFill(['released_at' => CarbonImmutable::now()])->save();
        }
    }

    return ['reservation' => $reservation, 'screening' => $screening, 'seats' => $seats];
}

/** 無料鑑賞券を1枚発行する。 */
function issueFreeTicket(User $user, ?CarbonImmutable $expiresAt = null): FreeTicket
{
    return FreeTicket::create([
        'user_id' => $user->id,
        'code' => 'FT'.str_pad((string) (FreeTicket::max('id') + 1), 8, '0', STR_PAD_LEFT),
        'issued_at' => CarbonImmutable::now(),
        // 4.5.2-4。有効期限は発行から1年。
        'expires_at' => $expiresAt ?? CarbonImmutable::now()->addYear(),
    ]);
}

beforeEach(function () {
    createCinema('gion', '祇園ムビ');
});

it('未ログインではログイン画面へ遷移する（7.14）', function () {
    $this->get(route('front.mypage.index'))->assertRedirect(route('login'));
});

it('スタンプ数と無料鑑賞券を表示する（7.14 構成要素1 / 4.5.1）', function () {
    $user = User::factory()->create();
    ['reservation' => $reservation] = mypageReservation($user);

    // 未交換のスタンプ2個（4.5.1 実装方針: 行数の集計で求める）。
    Stamp::create(['user_id' => $user->id, 'reservation_id' => $reservation->id]);

    $ticket = issueFreeTicket($user);

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(__('front.mypage.stamp.count', ['count' => 1]))
        // 4.5.1-2。5個で1枚のため、残り4個。
        ->assertSee(__('front.mypage.stamp.progress', ['remaining' => 4]))
        // 4.5.1-4 / 4.5.5。券を使った予約にはスタンプが付かないことを、使う前に知らせる。
        ->assertSee(__('front.mypage.stamp.free_ticket_note'))
        ->assertSee(__('front.mypage.free_ticket.count', ['count' => 1]))
        ->assertSee($ticket->code)
        // 12章 残課題31。使い道を約束しない。
        ->assertSee(__('front.mypage.free_ticket.pending'))
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('交換済みのスタンプは現在の個数に数えない（4.5.1-2）', function () {
    $user = User::factory()->create();
    ['reservation' => $first] = mypageReservation($user);
    ['reservation' => $second] = mypageReservation($user);

    $ticket = issueFreeTicket($user);

    // 交換に使ったスタンプは発行した券に紐づく（行は消さず履歴として残す）。
    Stamp::create(['user_id' => $user->id, 'reservation_id' => $first->id, 'free_ticket_id' => $ticket->id]);
    Stamp::create(['user_id' => $user->id, 'reservation_id' => $second->id]);

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(__('front.mypage.stamp.count', ['count' => 1]));
});

it('使用済みの無料鑑賞券は保有枚数に数えない（6.1 無料鑑賞券の使用状態の管理方式）', function () {
    $user = User::factory()->create();
    ['reservation' => $reservation] = mypageReservation($user);

    $ticket = issueFreeTicket($user);

    // **`used_at` ではなく `t_reservations.active_free_ticket_id` を真実源とする。**
    // 生成列のため、`paid` の予約が `free_ticket_id` を持つと自動的に埋まる。
    $reservation->forceFill(['free_ticket_id' => $ticket->id])->save();

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(__('front.mypage.free_ticket.none'))
        ->assertDontSee($ticket->code);
});

it('キャンセルすると無料鑑賞券は保有へ戻る（4.4 の処理3 / 4.3.18）', function () {
    $user = User::factory()->create();
    ['reservation' => $reservation] = mypageReservation($user);

    $ticket = issueFreeTicket($user);
    $reservation->forceFill(['free_ticket_id' => $ticket->id])->save();

    // 生成列は `status` の変更に追随するため、券を戻す処理を明示的に書かなくてよい。
    $reservation->forceFill(['status' => ReservationStatus::Cancelled, 'cancelled_at' => CarbonImmutable::now()])->save();

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee($ticket->code);
});

it('期限切れの無料鑑賞券は保有枚数に数えない（4.5.2-4）', function () {
    $user = User::factory()->create();
    $expired = issueFreeTicket($user, CarbonImmutable::now()->subDay());

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(__('front.mypage.free_ticket.none'))
        ->assertDontSee($expired->code);
});

it('これからの予約と過去の予約を上映終了時刻で振り分ける（7.14 構成要素2・3）', function () {
    $user = User::factory()->create();

    ['reservation' => $upcoming] = mypageReservation($user, startsAt: CarbonImmutable::now()->addDays(3));
    ['reservation' => $past] = mypageReservation($user, startsAt: CarbonImmutable::now()->subDays(3));

    $response = $this->actingAs($user)->get(route('front.mypage.index'))->assertOk();

    // 双方とも画面には出る。並びで「これから」が先に来ることを固定する。
    $response->assertSeeInOrder([
        __('front.mypage.upcoming.heading'),
        $upcoming->formattedReservationNo(),
        __('front.mypage.history.heading'),
        $past->formattedReservationNo(),
    ]);
});

it('上映中の予約はこれからの予約に残す（4.6.4-3）', function () {
    $user = User::factory()->create();

    // 開始済みだが終了前。上映開始後も入場できるため、まだ使えるチケットを過去へ送らない。
    ['reservation' => $reservation] = mypageReservation($user, startsAt: CarbonImmutable::now()->subMinutes(30));

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSeeInOrder([
            __('front.mypage.upcoming.heading'),
            $reservation->formattedReservationNo(),
            __('front.mypage.history.heading'),
        ]);
});

it('キャンセル済みは上映前でも履歴へ置く（7.14 構成要素3）', function () {
    $user = User::factory()->create();

    ['reservation' => $cancelled] = mypageReservation(
        $user,
        startsAt: CarbonImmutable::now()->addDays(3),
        status: ReservationStatus::Cancelled,
    );

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(__('front.mypage.upcoming.none'))
        ->assertSeeInOrder([
            __('front.mypage.history.heading'),
            $cancelled->formattedReservationNo(),
        ])
        ->assertSee(__('front.lookup.status.cancelled'));
});

it('お支払い前・期限切れの予約は表示しない（4.5.3）', function (ReservationStatus $status) {
    $user = User::factory()->create();
    ['reservation' => $reservation] = mypageReservation($user, status: $status);

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertDontSee($reservation->formattedReservationNo());
})->with([
    'pending' => [ReservationStatus::Pending],
    'expired' => [ReservationStatus::Expired],
]);

it('他の会員の予約は表示しない（17.15 T-11）', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    ['reservation' => $mine] = mypageReservation($user, startsAt: CarbonImmutable::now()->addDays(3));
    ['reservation' => $theirs] = mypageReservation($other, startsAt: CarbonImmutable::now()->addDays(3));

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee($mine->formattedReservationNo())
        ->assertDontSee($theirs->formattedReservationNo());
});

it('他の会員のスタンプと無料鑑賞券は数えない（17.15 T-11）', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    ['reservation' => $theirs] = mypageReservation($other);
    Stamp::create(['user_id' => $other->id, 'reservation_id' => $theirs->id]);
    $theirTicket = issueFreeTicket($other);

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(__('front.mypage.stamp.count', ['count' => 0]))
        ->assertSee(__('front.mypage.free_ticket.none'))
        ->assertDontSee($theirTicket->code);
});

it('会員情報の変更と退会の案内を出す（7.14 構成要素4・5 / 2.5.1）', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(route('profile.edit'))
        ->assertSee(__('front.mypage.account.withdrawal'));
});

it('過去の予約をページネーションする（7.14 構成要素3）', function () {
    $user = User::factory()->create();

    // 1ページ10件。11件目が2ページ目に送られることを確かめる。
    $numbers = collect(range(1, 11))
        ->map(fn (int $i): string => mypageReservation(
            $user,
            startsAt: CarbonImmutable::now()->subDays($i),
            seatCount: 1,
        )['reservation']->formattedReservationNo());

    $first = $this->actingAs($user)->get(route('front.mypage.index'))->assertOk();

    // 上映開始の新しい順。最も古い1件だけが溢れる。
    $first->assertSee($numbers->first())->assertDontSee($numbers->last());

    $this->actingAs($user)
        ->get(route('front.mypage.index', ['page' => 2]))
        ->assertOk()
        ->assertSee($numbers->last());
});

it('ページネーションを日本語かつ規約どおりの意匠で描く（20.1-3 / 13.5-2 / 18.2）', function () {
    $user = User::factory()->create();

    foreach (range(1, 11) as $i) {
        mypageReservation($user, startsAt: CarbonImmutable::now()->subDays($i), seatCount: 1);
    }

    $response = $this->actingAs($user)->get(route('front.mypage.index'))->assertOk();

    $response
        ->assertSee(__('pagination.next'))
        ->assertSee(__('front.pagination.summary', ['first' => 1, 'last' => 10, 'total' => 11]))
        // **フレームワーク同梱の既定ビューを使っていないこと。** 既定は英語の文言と
        // `sm:` ブレークポイント（13.5-2 が禁止）を出力する。
        ->assertDontSee('Next &raquo;', escape: false)
        ->assertDontSee('Showing')
        ->assertDontSee('sm:hidden', escape: false);
});

it('履歴のキャンセル済みに上映回が変わりうる旨の注記を出す（12章 残課題37・40）', function () {
    $user = User::factory()->create();

    mypageReservation($user, startsAt: CarbonImmutable::now()->addDays(3), status: ReservationStatus::Cancelled);

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee(__('front.lookup.cancelled_note'));
});

it('有効な予約には注記を出さない（12章 残課題37）', function () {
    $user = User::factory()->create();

    mypageReservation($user, startsAt: CarbonImmutable::now()->addDays(3));

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertDontSee(__('front.lookup.cancelled_note'));
});

it('used_at が入っていても、予約に使われていなければ保有として数える（4.5.3）', function () {
    $user = User::factory()->create();
    $ticket = issueFreeTicket($user);

    // **`used_at` は読まない**（6.1追記表の導出へ移行済み。判定は
    // `t_reservations.active_free_ticket_id` のみ）。この乖離を固定しておかないと、
    // 将来 `used_at` を見る実装へ静かに戻りうる。
    $ticket->forceFill(['used_at' => CarbonImmutable::now()])->save();

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee($ticket->code)
        ->assertSee(__('front.mypage.free_ticket.count', ['count' => 1]));
});

it('ログイン済みの会員がログイン画面を再訪するとマイページへ送る（4.5.3）', function () {
    // `config/fortify.php` の `home` と `bootstrap/app.php` の `redirectUsersTo()` は
    // どちらも「ログイン済みの会員が行く場所」であり、揃っていなければならない。
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('front.mypage.index'));
});

it('他館の cinema-admin のセッションが残っていても全館の予約が出る（13.4.1）', function () {
    $user = User::factory()->create();
    ['reservation' => $reservation] = mypageReservation($user, startsAt: CarbonImmutable::now()->addDays(3));

    // `screening.booking` は CinemaScope の対象。顧客側のルートは SkipCinemaScope の
    // 内側にあるため、管理者のセッションが同一ブラウザに残っていても絞り込まれない。
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, createCinema('other', 'ムビ他館')), 'admin')
        ->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        ->assertSee($reservation->formattedReservationNo());
});

it('ページ数が多い場合は番号を省略する（4.5.3）', function () {
    $user = User::factory()->create();

    // 1ページ10件。61件＝7ページ分。現在地の前後2ページだけを出し、間を省略する。
    foreach (range(1, 61) as $i) {
        mypageReservation($user, startsAt: CarbonImmutable::now()->subDays($i), seatCount: 1);
    }

    $this->actingAs($user)
        ->get(route('front.mypage.index'))
        ->assertOk()
        // 1ページ目にいるので 1〜3 と、末尾の 7 が出る。
        ->assertSee(__('front.pagination.goto', ['page' => 3]))
        ->assertSee(__('front.pagination.goto', ['page' => 7]))
        // 窓の外（4〜6）は出さない。全ページを並べるとモバイルで何行も折り返す。
        ->assertDontSee(__('front.pagination.goto', ['page' => 5]));
});
