<?php

namespace App\Listeners;

use App\Services\SeatLockService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Session;

/**
 * ログイン成功時に、座席ロックの保持者を `session:{id}` から `user:{id}` へ移す
 * （13.4.6 未実装事項 / 7.8 / 4.3.9）。
 *
 * **セッションIDの再生成より前に呼ばれること**が前提。Fortify は
 * `AttemptToAuthenticate`（`guard->login()` が本イベントを発火）→
 * `PrepareAuthenticatedSession`（`session()->regenerate()`）の順にパイプラインを
 * 実行するため、本リスナーの時点では移譲元の `session:{id}` をまだ読める。
 *
 * 会員登録（`RegisteredUserController`）も `Registered` の直後に `guard->login()` を
 * 呼ぶため、13.4.6 が求める「ログイン成功時・会員登録完了時の2箇所」は本リスナー
 * 1つで満たされる。`Registered` にも登録すると、まだログインしておらず移譲先の
 * `user:{id}` が確定していない時点で走る。
 *
 * 移譲そのものの条件（移譲元に有効なロックがある場合に限り移譲先の既存ロックを消す等）は
 * `SeatLockService::transfer()` が持つ。通常のログイン（座席選択を伴わない）で呼ばれても
 * 副作用が無いことも同メソッドが保証する。
 */
class TransferSeatLocksOnLogin
{
    public function __construct(private readonly SeatLockService $locks) {}

    public function handle(Login $event): void
    {
        // 管理者（admin ガード）は座席ロックを持たない。顧客の web ガードのみを対象とする。
        if ($event->guard !== 'web') {
            return;
        }

        $userId = $event->user->getAuthIdentifier();

        if (! is_int($userId) && ! is_string($userId)) {
            return;
        }

        $this->locks->transfer('session:'.Session::getId(), 'user:'.$userId);
    }
}
