<?php

use App\Enums\AdminRole;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\User;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;

/**
 * ログイン時の座席ロックの引き継ぎ（13.4.6 未実装事項 / 7.8 / 4.3.9）。
 *
 * P-31（座席選択）は非会員のロックを `session:{id}` で作る。ログインでセッションIDが
 * 再生成されると保持者キーが変わり、自分で取った座席を解除も再取得もできなくなる。
 *
 * リスナー（`TransferSeatLocksOnLogin`）は Laravel のイベント自動探索で登録される。
 * 探索が効かなくなれば本ファイルの1件目が落ちる。
 */

/**
 * 販売期間内の上映回と座席1件を作り、現在のセッションでロックを取得する。
 *
 * @return array{holderKey: string, seat: Seat}
 */
function acquireGuestSeatLock(): array
{
    $theater = createTheater();
    $seatType = makeSeatsWithSurcharge($theater, 1, 0);
    $seat = Seat::where('seat_type_id', $seatType->id)->sole();
    [$screening] = makeScreenings($theater, [CarbonImmutable::now()->addDay()->setTime(10, 0)]);

    $locks = app(SeatLockService::class);
    $holderKey = $locks->holderKey();

    expect($locks->acquire($screening, $seat, $holderKey))->toBeTrue();
    expect($holderKey)->toStartWith('session:');

    return ['holderKey' => $holderKey, 'seat' => $seat];
}

it('ログイン成功時に座席ロックの保持者を user:{id} へ移す', function () {
    ['seat' => $seat] = acquireGuestSeatLock();
    $user = User::factory()->create();

    event(new Login('web', $user, false));

    expect(SeatLock::where('seat_id', $seat->id)->sole()->holder_key)->toBe('user:'.$user->id);
});

it('管理者のログイン（admin ガード）では座席ロックに触れない', function () {
    ['holderKey' => $holderKey, 'seat' => $seat] = acquireGuestSeatLock();

    event(new Login('admin', createAdmin(AdminRole::SuperAdmin), false));

    expect(SeatLock::where('seat_id', $seat->id)->sole()->holder_key)->toBe($holderKey);
});
