<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;

/**
 * 予約状況の確認（A-10）と予約検索（A-11）の権限を判定する。両画面とも
 * 参照のみで、必要なアビリティは `viewAny` だけである。
 *
 * 4.8.2 は予約状況の確認を `super-admin`（全館）と `cinema-admin`（自館のみ）に
 * 許可し、`gate` は入場確認のみを行う（17.1.3）。館の範囲は本Policyでは扱わず、
 * `CinemaScope` 適用済みの `Booking` を `screening.booking` 経由でたどって担保する
 * （4.8.6追記表「A-10の館スコープ」。A-09 と同じ方式）。
 *
 * `AuthorizeAdminScreen` ミドルウェアはフルページロードのみを保護し
 * `/livewire/update` 経由のアクション呼び出しには適用されないため（4.8.6追記表）、
 * 一覧取得・明細表示のたびにこのPolicyで判定する。
 */
class ReservationPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->is_active && $admin->role !== AdminRole::Gate;
    }
}
