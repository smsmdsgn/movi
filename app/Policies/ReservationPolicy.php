<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CompletedReservations;

/**
 * 予約状況の確認（A-10）・予約検索（A-11）と、顧客側の予約の参照（P-38）の権限を
 * 判定する。いずれも参照のみである。
 *
 * **アビリティごとに判定の相手が異なる。** `viewAny` は管理者（`Admin`、管理者ガード。
 * `Gate::forUser($admin)` から呼ぶ）、`view` は顧客（`User`、既定のガード。非会員は
 * ゲストのため null）を受ける。予約は管理者と顧客の双方が参照する唯一のモデルであり、
 * Laravel はモデルに対して1つの Policy しか結び付けないため、同一クラスに両者を置く。
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

    /**
     * 顧客が自身の予約を参照できるか（P-38、17.2.1-1 / 12章 旧残課題34）。
     *
     * **予約番号の推測困難性に依存しない。** 会員は所有者（`user_id`）の一致で、
     * 非会員は確定させたブラウザのセッション（`CompletedReservations`）で判定する。
     * 会員の予約であっても、確定直後のブラウザであれば参照できる（ログインの有無を
     * 問わず、確定した当人が完了画面を開けること自体は妨げない）。
     *
     * **ゲストでも呼ばれるよう `$user` を nullable とする**（第1引数が nullable の
     * Policy メソッドは未ログインでも実行される）。予約フローは非会員が通るため、
     * ゲストを一律に拒むと非会員が自分の完了画面に到達できない。
     *
     * 4.3.5 の照合（予約番号＋メールアドレス）を求める経路は予約照会（P-07）が
     * 受け持つ。セッションを失った利用者はそちらへ案内する（7.13-6）。
     */
    public function view(?User $user, Reservation $reservation): bool
    {
        if ($user !== null && $reservation->user_id === $user->id) {
            return true;
        }

        return app(CompletedReservations::class)->includes($reservation);
    }
}
