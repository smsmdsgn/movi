<?php

use App\Enums\CancellationOutcome;
use App\Enums\CheckInRevocation;
use App\Enums\EntryOutcome;
use App\Enums\ReservationStatus;
use App\Services\EntryResult;
use App\Services\EntryService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * `EntryService::revokeCheckIn()`（4.6.5）の期限判定と、入場の記録・取消と顧客の
 * キャンセル（4.4-5）の連携に絞る。拒否理由の分岐（4.6.4）は画面越しに
 * `tests/Feature/Admin/EntryGateTest.php` が確かめるため、ここでは重複させない。
 */

it('入場済みの予約を取り消せる（4.6.5）', function () {
    $ctx = makeTodayScreening();
    $ctx['screening']->update(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
    $reservation = makeSeatedReservation($ctx);
    $reservation->forceFill(['checked_in_at' => now()])->save();

    $outcome = app(EntryService::class)->revokeCheckIn($reservation);

    expect($outcome)->toBe(CheckInRevocation::Revoked)
        ->and($reservation->refresh()->checked_in_at)->toBeNull();
});

it('未入場の予約は取り消せない（NotCheckedIn）', function () {
    $ctx = makeTodayScreening();
    $ctx['screening']->update(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
    $reservation = makeSeatedReservation($ctx);

    $outcome = app(EntryService::class)->revokeCheckIn($reservation);

    expect($outcome)->toBe(CheckInRevocation::NotCheckedIn);
});

it('終了時刻ちょうどは取り消せる（境界）', function () {
    // DB は秒未満を保持しないため、マイクロ秒を含む値のまま比較すると
    // 「ちょうど」の等値判定がずれる。秒単位に切り詰める。
    $now = CarbonImmutable::now()->startOfSecond();
    $ctx = makeTodayScreening();
    $ctx['screening']->update(['starts_at' => $now->subHours(3), 'ends_at' => $now]);
    $reservation = makeSeatedReservation($ctx);
    $reservation->forceFill(['checked_in_at' => $now->subHour()])->save();

    $outcome = app(EntryService::class)->revokeCheckIn($reservation, $now);

    expect($outcome)->toBe(CheckInRevocation::Revoked)
        ->and($reservation->refresh()->checked_in_at)->toBeNull();
});

it('終了後は取り消せず、checked_in_at が残る（4.6.5「期限」）', function () {
    $now = CarbonImmutable::now();
    $ctx = makeTodayScreening();
    $ctx['screening']->update(['starts_at' => $now->subHours(3), 'ends_at' => $now->subMinute()]);
    $reservation = makeSeatedReservation($ctx);
    $reservation->forceFill(['checked_in_at' => $now->subHour()])->save();

    $outcome = app(EntryService::class)->revokeCheckIn($reservation, $now);

    expect($outcome)->toBe(CheckInRevocation::Ended)
        ->and($reservation->refresh()->checked_in_at)->not->toBeNull();
});

it('取消の後は再び入場できる（4.6.5）', function () {
    $ctx = makeTodayScreening();
    $ctx['screening']->update(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addHours(2)]);
    $reservation = makeSeatedReservation($ctx, ['entry_code' => Str::random(32)]);
    $reservation->forceFill(['checked_in_at' => now()])->save();

    $entries = app(EntryService::class);
    $entries->revokeCheckIn($reservation);

    $cinema = $ctx['screening']->booking->cinema;
    $result = $entries->admitByEntryCode($cinema, $reservation->entry_code);

    expect($result->outcome)->toBe(EntryOutcome::Admitted)
        ->and($reservation->refresh()->checked_in_at)->not->toBeNull();
});

it('入場の記録と取消は顧客のキャンセルの可否に反映される（4.4-5 / 4.6.5）', function () {
    fakeStripeService(settledCharge(0));
    // 入場の受付（上映開始の60分前から）とキャンセルの受付（20分前まで）が重なる時間帯に置く。
    $ctx = makeTodayScreening();
    $ctx['screening']->update(['starts_at' => now()->addMinutes(40), 'ends_at' => now()->addHours(3)]);
    $reservation = makeSeatedReservation($ctx, ['entry_code' => Str::random(32), 'total_amount' => 0], seatAmount: 0);
    $entries = app(EntryService::class);
    $cinema = $ctx['screening']->booking->cinema;

    expect($entries->admitByEntryCode($cinema, $reservation->entry_code)->outcome)->toBe(EntryOutcome::Admitted);

    $rejected = app(ReservationService::class)->cancel($reservation->refresh());

    expect($rejected->outcome)->toBe(CancellationOutcome::Rejected)
        ->and($rejected->messageKey)->toBe('front.cancel.errors.checked_in');

    expect($entries->revokeCheckIn($reservation))->toBe(CheckInRevocation::Revoked);

    $cancelled = app(ReservationService::class)->cancel($reservation->refresh());

    expect($cancelled->outcome)->toBe(CancellationOutcome::Cancelled)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);

    // キャンセル済みには入場を記録しない（4.6.4-2）。
    expect($entries->admitByEntryCode($cinema, $reservation->entry_code)->outcome)->toBe(EntryOutcome::Cancelled);
});

it('判定結果と予約の有無の取り違えを生成時に拒む（17.2.1-3）', function () {
    $reservation = makeSeatedReservation(makeTodayScreening());

    expect(fn () => EntryResult::rejected(EntryOutcome::OtherCinema, $reservation))->toThrow(LogicException::class)
        ->and(fn () => EntryResult::unidentified(EntryOutcome::AlreadyCheckedIn))->toThrow(LogicException::class);
});
