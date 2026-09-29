<?php

use App\Enums\AdminRole;
use App\Enums\ReservationStatus;
use App\Livewire\Admin\EntryGate\Index;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\Screening;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * 入場ゲート（A-16、4.6.3〜4.6.5）。判定の分岐（4.6.4）・手入力モード・館スコープ・
 * 3秒表示の計時制御を固定する。QRコードそのものの発行は EntryQrCode が担う
 * （本テストは `admit()` に入場コード文字列を直接渡すため、QRの読み取りは対象外）。
 */

/**
 * 入場ゲート用に、指定した上映開始時刻の上映回・座席・予約を1件作る。
 * `entry_code` は32文字の英数字（4.6.2-1）で既定生成する。
 *
 * @param  array<string, mixed>  $overrides
 * @return array{screening: Screening, cinema: Cinema, reservation: Reservation}
 */
function gateReservation(CarbonImmutable $startsAt, array $overrides = []): array
{
    $ctx = makeTodayScreening();
    $endsAt = $overrides['ends_at'] ?? $startsAt->addHours(2);
    unset($overrides['ends_at']);

    $ctx['screening']->update(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    $ctx['screening']->refresh();

    $reservation = makeSeatedReservation($ctx, array_merge([
        'entry_code' => Str::random(32),
    ], $overrides));

    return [
        'screening' => $ctx['screening'],
        'seat' => $ctx['seat'],
        'cinema' => $ctx['screening']->booking->cinema,
        'reservation' => $reservation,
    ];
}

it('gate・cinema-admin・super-admin が入場ゲート（A-16）に到達できる（4.8.5）', function (AdminRole $role, bool $needsCinema) {
    $cinema = $needsCinema ? createCinema() : null;

    $this->actingAs(createAdmin($role, $cinema), 'admin')
        ->get(route('admin.gate.index'))
        ->assertOk()
        ->assertSee(__('admin.gate.title'));
})->with([
    'gate' => [AdminRole::Gate, true],
    'cinema-admin' => [AdminRole::CinemaAdmin, true],
    'super-admin' => [AdminRole::SuperAdmin, false],
]);

it('入場コードで入場を記録する（4.6.3 動作2〜4）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation, 'seat' => $seat] = gateReservation($now->addMinutes(30));
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSet('result.admitted', true)
        ->assertSee('テスト作品')
        ->assertSee($seat->displayName());

    expect($reservation->refresh()->checked_in_at)->not->toBeNull();
});

it('未登録の入場コードは該当なしとする', function () {
    $this->actingAs(createAdmin(AdminRole::Gate, createCinema()), 'admin');

    Livewire::test(Index::class)
        ->call('admit', Str::random(32))
        ->assertSee(__('admin.gate.outcome.not_found'));
});

it('形式に合わない文字列は該当なしとする（4.6.3 動作2）', function () {
    $this->actingAs(createAdmin(AdminRole::Gate, createCinema()), 'admin');

    Livewire::test(Index::class)
        ->call('admit', 'not-a-valid-entry-code')
        ->assertSee(__('admin.gate.outcome.not_found'));
});

it('他館の予約は理由のみを示し、予約者名は表示しない（4.6.4-1 / 17.2.1-3）', function () {
    $now = CarbonImmutable::now();
    ['reservation' => $reservation] = gateReservation($now->addMinutes(30), ['guest_name' => '他館 太郎']);
    $otherCinema = createCinema('other-gate', 'ムビ他館');
    $this->actingAs(createAdmin(AdminRole::Gate, $otherCinema), 'admin');

    Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSee(__('admin.gate.outcome.other_cinema'))
        ->assertDontSee('他館 太郎');
});

it('キャンセル済みの予約は入場できない（4.6.4-2）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30), [
        'status' => ReservationStatus::Cancelled,
    ]);
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSee(__('admin.gate.outcome.cancelled'));
});

it('確定していない予約（pending）は手入力でも入場できない（4.6.4-2）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30), [
        'status' => ReservationStatus::Pending,
        'entry_code' => null,
        'reservation_no' => '12345678',
    ]);
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->set('reservationNo', $reservation->reservation_no)
        ->call('admitByReservationNo')
        ->assertSee(__('admin.gate.outcome.not_confirmed'));
});

it('入場開始前は入場できない（4.6.4-3）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(90));
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSee(__('admin.gate.outcome.too_early', ['minutes' => Screening::ENTRY_OPENS_MINUTES_BEFORE]));

    expect($reservation->refresh()->checked_in_at)->toBeNull();
});

it('受付開始ちょうどは入場でき、その1秒前は入場できない（4.6.4-3 境界）', function (int $secondsFromOpening, bool $admitted) {
    // DB は秒未満を保持しないため、秒単位に切り詰めて「ちょうど」を作る。
    $opensAt = CarbonImmutable::now()->startOfSecond();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($opensAt->addMinutes(Screening::ENTRY_OPENS_MINUTES_BEFORE));

    CarbonImmutable::setTestNow($opensAt->addSeconds($secondsFromOpening));
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSet('result.admitted', $admitted);

    CarbonImmutable::setTestNow();
})->with([
    '受付開始ちょうど' => [0, true],
    '受付開始の1秒前' => [-1, false],
]);

it('所属館の無い gate アカウントは 403 とする（設定漏れを空の画面にしない。4.8.6追記表）', function () {
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin')
        ->get(route('admin.gate.index'))
        ->assertForbidden();
});

it('QRの照会はアカウントごとに1分あたり60回までとする（17.2.2）', function () {
    $this->freezeTime();
    $cinema = createCinema();
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');
    $component = Livewire::test(Index::class);

    for ($i = 0; $i < 60; $i++) {
        $component->call('admit', Str::random(32));
    }

    $component->call('admit', Str::random(32))
        ->assertSet('result.admitted', false)
        ->assertSet('result.message', __('admin.gate.errors.throttled', ['seconds' => 60]));

    // 同じアカウントでも、別の端末（ログインセッション）は上限を共有しない。
    session()->regenerate();

    Livewire::test(Index::class)
        ->call('admit', Str::random(32))
        ->assertSee(__('admin.gate.outcome.not_found'));
});

it('予約番号の手入力はアカウントごとに1分あたり10回までとし、上限後は照会しない（17.2.2）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30));
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');
    $component = Livewire::test(Index::class);

    for ($i = 0; $i < 10; $i++) {
        $component->set('reservationNo', '99999999')->call('admitByReservationNo');
    }

    $component->set('reservationNo', $reservation->reservation_no)
        ->call('admitByReservationNo')
        ->assertHasErrors('reservationNo');

    expect($reservation->refresh()->checked_in_at)->toBeNull();
});

it('形式違いの手入力は照会の回数に数えない（17.2.2）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30));
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');
    $component = Livewire::test(Index::class);

    for ($i = 0; $i < 10; $i++) {
        $component->set('reservationNo', '12-34')->call('admitByReservationNo');
    }

    $component->set('reservationNo', $reservation->reservation_no)
        ->call('admitByReservationNo')
        ->assertSet('result.admitted', true);
});

it('終了時刻ちょうどは入場できる（4.6.4-3 境界）', function () {
    // DB は秒未満を保持しないため、マイクロ秒を含む値のまま比較すると
    // 「ちょうど」の等値判定がずれる。秒単位に切り詰める。
    $now = CarbonImmutable::now()->startOfSecond();
    $startsAt = $now->subHours(3);
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($startsAt, ['ends_at' => $now]);

    CarbonImmutable::setTestNow($now);
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSet('result.admitted', true);

    CarbonImmutable::setTestNow();
});

it('上映終了後は入場できない（4.6.4-3）', function () {
    $now = CarbonImmutable::now();
    $startsAt = $now->subHours(3);
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($startsAt, ['ends_at' => $now->subMinute()]);
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSee(__('admin.gate.outcome.ended'));
});

it('入場済みの予約は再度読み取っても checked_in_at が上書きされない（4.6.4-4）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30));
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)->call('admit', $reservation->entry_code);
    $firstCheckedInAt = $reservation->refresh()->checked_in_at;
    expect($firstCheckedInAt)->not->toBeNull();

    Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSee(__('admin.gate.outcome.already_checked_in'));

    expect($reservation->refresh()->checked_in_at->equalTo($firstCheckedInAt))->toBeTrue();
});

it('手入力の予約番号はハイフンの有無を問わず入場できる（4.6.3 手入力モード）', function (string $format) {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30), [
        'reservation_no' => '12345678',
    ]);
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->set('reservationNo', $format)
        ->call('admitByReservationNo')
        ->assertSet('result.admitted', true);

    expect($reservation->refresh()->checked_in_at)->not->toBeNull();
})->with([
    'ハイフンなし' => ['12345678'],
    'ハイフンあり' => ['1234-5678'],
]);

it('手入力の形式が誤っている場合は検証エラーとし、入場を記録しない', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30), [
        'reservation_no' => '12345678',
    ]);
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    Livewire::test(Index::class)
        ->set('reservationNo', '1234')
        ->call('admitByReservationNo')
        ->assertHasErrors('reservationNo');

    expect($reservation->refresh()->checked_in_at)->toBeNull();
});

it('super-admin は館を選ぶまで入場を記録しない（4.8.5「入場ゲート」）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30));
    $this->actingAs(createAdmin(AdminRole::SuperAdmin), 'admin');

    $component = Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSet('result', null);

    expect($reservation->refresh()->checked_in_at)->toBeNull();

    $component->set('selectedCinemaId', $cinema->id)
        ->call('admit', $reservation->entry_code)
        ->assertSet('result.admitted', true);

    expect($reservation->refresh()->checked_in_at)->not->toBeNull();
});

it('cinema-admin は selectedCinemaId を書き換えても自館の端末として判定される（17.2.1-3）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $otherCinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30), [
        'guest_name' => '他館 花子',
    ]);
    $myCinema = createCinema('my-gate-cinema', 'ムビ自館');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $myCinema), 'admin');

    Livewire::test(Index::class)
        ->set('selectedCinemaId', $otherCinema->id)
        ->call('admit', $reservation->entry_code)
        ->assertSee(__('admin.gate.outcome.other_cinema'))
        ->assertDontSee('他館 花子');
});

it('dismiss は一致する通し番号のときだけ結果を消す（4.6.3 動作6）', function () {
    $now = CarbonImmutable::now();
    ['cinema' => $cinema, 'reservation' => $reservation] = gateReservation($now->addMinutes(30));
    $this->actingAs(createAdmin(AdminRole::Gate, $cinema), 'admin');

    $component = Livewire::test(Index::class)
        ->call('admit', $reservation->entry_code)
        ->assertSet('resultSequence', 1);

    $component->call('dismiss', 0)->assertSet('result.admitted', true);
    $component->call('dismiss', 1)->assertSet('result', null);
});

it('result・resultSequence は Locked のためクライアントから変更できない', function (string $property, mixed $value) {
    $this->actingAs(createAdmin(AdminRole::Gate, createCinema()), 'admin');

    Livewire::test(Index::class)->set($property, $value);
})->throws(CannotUpdateLockedPropertyException::class)->with([
    'result' => ['result', ['admitted' => true]],
    'resultSequence' => ['resultSequence', 99],
]);

it('無効化された管理者は入場の記録を拒否される（17.1.2-6 / checkIn）', function () {
    $admin = createAdmin(AdminRole::Gate, createCinema());
    $admin->is_active = false;
    $admin->save();

    expect(Gate::forUser($admin)->allows('checkIn', Reservation::class))->toBeFalse();
});
