<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\FreeTicket;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Stamp;
use App\Models\User;
use App\Services\StampService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/*
 * スタンプ付与（B-03、10章 / 4.5.1 / 4.5.5）。**付与の条件・二重付与の防止・
 * 無料鑑賞券への交換**を固定する。
 */

/**
 * 会員の予約を1件作る。既定は上映開始を過ぎた決済済み（＝付与の対象）。
 */
function stampReservation(
    User $user,
    ?CarbonImmutable $startsAt = null,
    ReservationStatus $status = ReservationStatus::Paid,
    ?FreeTicket $freeTicket = null,
): Reservation {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture(1);
    $ticketType = adultTicket(2000);

    $startsAt ??= CarbonImmutable::now()->subHours(3);
    $screening->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2)]);

    $reservation = Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'user_id' => $user->id,
        'contact_type' => ContactType::Member,
        'screening_id' => $screening->id,
        'status' => $status,
        'total_amount' => 2000,
        'free_ticket_id' => $freeTicket?->id,
    ]);

    ReservationSeat::create([
        'reservation_id' => $reservation->id,
        'screening_id' => $screening->id,
        'seat_id' => $seats[0]->id,
        'ticket_type_id' => $ticketType->id,
        'amount' => 2000,
    ]);

    return $reservation;
}

/** 非会員の予約を1件作る（上映開始を過ぎた決済済み）。 */
function guestStampReservation(): Reservation
{
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture(1);
    $ticketType = adultTicket(2000);

    $startsAt = CarbonImmutable::now()->subHours(3);
    $screening->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2)]);

    $reservation = Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'contact_type' => ContactType::Guest,
        'guest_name' => '祇園　太郎',
        'guest_name_kana' => 'ギオン　タロウ',
        'guest_email' => 'taro@example.test',
        'guest_phone' => '09012345678',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Paid,
        'total_amount' => 2000,
    ]);

    ReservationSeat::create([
        'reservation_id' => $reservation->id,
        'screening_id' => $screening->id,
        'seat_id' => $seats[0]->id,
        'ticket_type_id' => $ticketType->id,
        'amount' => 2000,
    ]);

    return $reservation;
}

/** 発行済みの無料鑑賞券を1枚作る。 */
function issuedFreeTicket(User $user): FreeTicket
{
    return FreeTicket::create([
        'user_id' => $user->id,
        'code' => 'SEED'.str_pad((string) (FreeTicket::max('id') + 1), 8, '0', STR_PAD_LEFT),
        'issued_at' => CarbonImmutable::now(),
        'expires_at' => CarbonImmutable::now()->addYear(),
    ]);
}

it('上映開始を経過した決済済みの会員予約にスタンプを付与する（4.5.1-5）', function () {
    $user = User::factory()->create();
    $reservation = stampReservation($user);

    $result = app(StampService::class)->grant();

    expect($result->granted)->toBe(1)
        ->and($result->issued)->toBe(0)
        ->and(Stamp::where('reservation_id', $reservation->id)->exists())->toBeTrue()
        ->and($user->unexchangedStamps()->count())->toBe(1);
});

it('入場していなくても付与する（4.5.1 の根拠）', function () {
    $user = User::factory()->create();
    $reservation = stampReservation($user);

    expect($reservation->checked_in_at)->toBeNull();

    app(StampService::class)->grant();

    expect(Stamp::where('reservation_id', $reservation->id)->exists())->toBeTrue();
});

it('対象外の予約には付与しない', function (Closure $make) {
    $user = User::factory()->create();
    $reservation = $make($user);

    $result = app(StampService::class)->grant();

    expect($result->granted)->toBe(0)
        ->and(Stamp::where('reservation_id', $reservation->id)->exists())->toBeFalse();
})->with([
    // 4.5.1-5。上映が始まっていない。
    '上映前' => [fn (User $user) => stampReservation($user, startsAt: CarbonImmutable::now()->addDay())],
    // 4.5.1-5「決済済み予約」。
    'キャンセル済み' => [fn (User $user) => stampReservation($user, status: ReservationStatus::Cancelled)],
    'お支払い前' => [fn (User $user) => stampReservation($user, status: ReservationStatus::Pending)],
    '期限切れ' => [fn (User $user) => stampReservation($user, status: ReservationStatus::Expired)],
    // 4.5.1-4。無料鑑賞券を使った予約（12章 残課題5 の確定。4.5.5）。
    '無料鑑賞券を使用' => [fn (User $user) => stampReservation($user, freeTicket: issuedFreeTicket($user))],
]);

it('上映開始ちょうどの回は付与の対象とする（4.5.1-5「開始時点」）', function () {
    $user = User::factory()->create();
    $now = CarbonImmutable::now()->startOfSecond();
    CarbonImmutable::setTestNow($now);

    $reservation = stampReservation($user, startsAt: $now);

    expect(app(StampService::class)->grant()->granted)->toBe(1)
        ->and(Stamp::where('reservation_id', $reservation->id)->exists())->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('非会員の予約には付与しない（4.5.1 は会員特典）', function () {
    $reservation = guestStampReservation();

    $result = app(StampService::class)->grant();

    expect($result->granted)->toBe(0)
        ->and(Stamp::where('reservation_id', $reservation->id)->exists())->toBeFalse();
});

it('二重に実行しても付与は1個に留まる（10章）', function () {
    $user = User::factory()->create();
    $reservation = stampReservation($user);

    $first = app(StampService::class)->grant();
    $second = app(StampService::class)->grant();

    expect($first->granted)->toBe(1)
        ->and($second->granted)->toBe(0)
        ->and(Stamp::where('reservation_id', $reservation->id)->count())->toBe(1);
});

it('同じ予約への二重付与は一意制約が止める（10章 / 4.5.5）', function () {
    $user = User::factory()->create();
    $reservation = stampReservation($user);

    Stamp::create(['user_id' => $user->id, 'reservation_id' => $reservation->id]);

    // **`StampService::grantOne()` が握る例外はこれである。** 多重実行が同時に同じ
    // 予約を読んだ場合の最後の歯止めであり、例外の型が変われば付与が止まる。
    expect(fn () => Stamp::create(['user_id' => $user->id, 'reservation_id' => $reservation->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('交換を二重に実行しても券は増えない（4.5.1-2）', function () {
    $user = User::factory()->create();

    foreach (range(1, FreeTicket::STAMPS_PER_TICKET) as $ignored) {
        stampReservation($user);
    }

    app(StampService::class)->grant();
    $second = app(StampService::class)->grant();

    expect($second->issued)->toBe(0)
        ->and(FreeTicket::where('user_id', $user->id)->count())->toBe(1);
});

it('付与が無い回でも、たまったままの会員を拾って交換する（4.5.5「交換の対象」）', function () {
    $user = User::factory()->create();

    // 前回の実行が付与だけ済ませて落ちた状態を作る（交換の対象を「今回付与した会員」に
    // 絞ると、この会員は次に鑑賞するまで取り残される）。
    foreach (range(1, FreeTicket::STAMPS_PER_TICKET) as $ignored) {
        $reservation = stampReservation($user);
        Stamp::create(['user_id' => $user->id, 'reservation_id' => $reservation->id]);
    }

    $result = app(StampService::class)->grant();

    expect($result->granted)->toBe(0)
        ->and($result->issued)->toBe(1)
        ->and($user->unexchangedStamps()->count())->toBe(0);
});

it('スタンプが5個たまると無料鑑賞券を1枚発行し、交換先を記録する（4.5.1-2）', function () {
    $user = User::factory()->create();

    foreach (range(1, FreeTicket::STAMPS_PER_TICKET) as $ignored) {
        stampReservation($user);
    }

    $result = app(StampService::class)->grant();

    $ticket = FreeTicket::where('user_id', $user->id)->sole();

    expect($result->granted)->toBe(FreeTicket::STAMPS_PER_TICKET)
        ->and($result->issued)->toBe(1)
        // 4.5.1-2「スタンプ数を0にリセット」は、行の削除ではなく交換先の記録で表す（4.5.3）。
        ->and($user->unexchangedStamps()->count())->toBe(0)
        ->and(Stamp::where('free_ticket_id', $ticket->id)->count())->toBe(FreeTicket::STAMPS_PER_TICKET)
        // 4.5.2-4。有効期限は発行から1年。
        ->and($ticket->expires_at->toDateString())->toBe($ticket->issued_at->addYear()->toDateString())
        // 13.3。コードは12桁の英数字（大文字）。
        ->and($ticket->code)->toMatch('/\A[A-Z0-9]{12}\z/');
});

it('一度に到達した分だけ発行する（10個なら2枚）', function () {
    $user = User::factory()->create();

    foreach (range(1, FreeTicket::STAMPS_PER_TICKET * 2) as $ignored) {
        stampReservation($user);
    }

    $result = app(StampService::class)->grant();

    expect($result->issued)->toBe(2)
        ->and(FreeTicket::where('user_id', $user->id)->count())->toBe(2)
        ->and($user->unexchangedStamps()->count())->toBe(0);
});

it('5個に満たない分は交換せず次回へ持ち越す（4.5.1-2）', function () {
    $user = User::factory()->create();

    foreach (range(1, FreeTicket::STAMPS_PER_TICKET - 1) as $ignored) {
        stampReservation($user);
    }

    $result = app(StampService::class)->grant();

    expect($result->issued)->toBe(0)
        ->and(FreeTicket::where('user_id', $user->id)->count())->toBe(0)
        ->and($user->unexchangedStamps()->count())->toBe(FreeTicket::STAMPS_PER_TICKET - 1);

    // 次の鑑賞で5個に達し、そこで発行される。
    stampReservation($user);

    expect(app(StampService::class)->grant()->issued)->toBe(1)
        ->and($user->unexchangedStamps()->count())->toBe(0);
});

it('上限に達した分は次回の実行で付与する（10章 B-03）', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        stampReservation($user);
    }

    $first = app(StampService::class)->grant(limit: 2);

    expect($first->granted)->toBe(2)
        ->and($first->reachedLimit(2))->toBeTrue()
        ->and($user->stamps()->count())->toBe(2);

    $second = app(StampService::class)->grant(limit: 2);

    expect($second->granted)->toBe(1)
        ->and($second->reachedLimit(2))->toBeFalse()
        ->and($user->stamps()->count())->toBe(3);
});

it('会員ごとに交換を判定する（他の会員のスタンプと合算しない）', function () {
    $taro = User::factory()->create();
    $hanako = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        stampReservation($taro);
    }

    foreach (range(1, 2) as $ignored) {
        stampReservation($hanako);
    }

    $result = app(StampService::class)->grant();

    expect($result->granted)->toBe(5)
        ->and($result->issued)->toBe(0)
        ->and(FreeTicket::count())->toBe(0);
});

it('コマンドから実行できる（10章 B-03）', function () {
    $user = User::factory()->create();

    foreach (range(1, FreeTicket::STAMPS_PER_TICKET) as $ignored) {
        stampReservation($user);
    }

    $this->artisan('stamps:grant')
        ->expectsOutputToContain('スタンプを 5 個付与し、無料鑑賞券を 1 枚発行しました。')
        ->assertSuccessful();

    expect(FreeTicket::where('user_id', $user->id)->count())->toBe(1);
});

it('コマンドは上限に達したことを知らせる（10章 B-03）', function () {
    $user = User::factory()->create();

    foreach (range(1, 2) as $ignored) {
        stampReservation($user);
    }

    $this->artisan('stamps:grant', ['--limit' => 1])
        ->expectsOutputToContain('上限（1 件）に達しました。')
        ->assertSuccessful();

    expect($user->stamps()->count())->toBe(1);
});

it('1人の交換に失敗しても残りの会員は続ける（4.5.5「交換の失敗」）', function () {
    $failing = User::factory()->create();
    $succeeding = User::factory()->create();

    foreach ([$failing, $succeeding] as $user) {
        foreach (range(1, FreeTicket::STAMPS_PER_TICKET) as $ignored) {
            stampReservation($user);
        }
    }

    // 片方の会員だけ券の発行が落ちる状況を作る。
    FreeTicket::creating(function (FreeTicket $ticket) use ($failing): void {
        if ((int) $ticket->user_id === $failing->id) {
            throw new RuntimeException('発行に失敗しました。');
        }
    });

    $result = app(StampService::class)->grant();

    FreeTicket::flushEventListeners();

    expect($result->failed)->toBe(1)
        ->and($result->issued)->toBe(1)
        // 失敗した会員のスタンプは交換されず残り、次回の実行が拾い直す。
        ->and($failing->unexchangedStamps()->count())->toBe(FreeTicket::STAMPS_PER_TICKET)
        ->and(FreeTicket::where('user_id', $failing->id)->count())->toBe(0)
        // 巻き添えにしない。
        ->and($succeeding->unexchangedStamps()->count())->toBe(0)
        ->and(FreeTicket::where('user_id', $succeeding->id)->count())->toBe(1);
});

it('交換の失敗を記録するとき、例外メッセージを残さない（17.4.3）', function () {
    $user = User::factory()->create();

    foreach (range(1, FreeTicket::STAMPS_PER_TICKET) as $ignored) {
        stampReservation($user);
    }

    FreeTicket::creating(function (): void {
        throw new RuntimeException('発行に失敗しました。');
    });

    Log::spy();

    app(StampService::class)->grant();

    FreeTicket::flushEventListeners();

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        // **例外メッセージを出さない。** `QueryException` のメッセージにはバインド値を
        // 埋めた SQL が載り、無料鑑賞券コードがログへ残る（17.4.3 / 4.3.15）。
        return array_keys($context) === ['exception', 'user_id']
            && is_string($context['exception'])
            && is_int($context['user_id']);
    });
});

it('何も起きなかった回は実行結果をログへ残さない（4.5.5「実行結果のログ」）', function () {
    Log::spy();

    $this->artisan('stamps:grant')->assertSuccessful();

    Log::shouldNotHaveReceived('info');
});

it('交換に失敗した回は失敗として終了する（10章）', function () {
    $user = User::factory()->create();

    foreach (range(1, FreeTicket::STAMPS_PER_TICKET) as $ignored) {
        stampReservation($user);
    }

    FreeTicket::creating(function (): void {
        throw new RuntimeException('発行に失敗しました。');
    });

    $this->artisan('stamps:grant')
        ->expectsOutputToContain('1 名の無料鑑賞券の発行に失敗しました。')
        ->assertExitCode(Command::FAILURE);

    FreeTicket::flushEventListeners();

    expect(FreeTicket::count())->toBe(0)
        // 付与そのものは済んでいる（次回は交換だけをやり直す）。
        ->and($user->unexchangedStamps()->count())->toBe(FreeTicket::STAMPS_PER_TICKET);
});

it('不正な --limit は黙って解釈せず失敗させる', function () {
    $user = User::factory()->create();
    stampReservation($user);

    $this->artisan('stamps:grant', ['--limit' => 'abc'])
        ->assertExitCode(Command::INVALID);

    // 1件と解釈して消化を始めてしまっていないこと。
    expect($user->stamps()->count())->toBe(0);
});

it('10分ごとに実行するようスケジュールへ登録する（10章 B-03）', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'stamps:grant'));

    // **`schedule:run` の停止が機能停止に直結する**（10章の【根拠】）。定義そのものを固定する。
    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        // **既定（24時間）を使わない**（4.5.5「交換の排他」）。強制終了で残ったロックが
        // 丸1日 B-03 を止めることを避けるため、期限を明示している。
        ->and($event->expiresAt)->toBe(15);
});
