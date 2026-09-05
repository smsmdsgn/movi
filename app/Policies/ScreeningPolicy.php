<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Screening;

/**
 * 上映回（A-09）の閲覧・作成・編集・削除可否を判定する（4.8.2）。
 * 上映回は `super-admin`（全館）・`cinema-admin`（自館のみ）の双方が編集できる
 * ため、役割で分岐するのは `gate` の拒否のみとなる。
 *
 * 館の範囲は `Screening` 自身ではなく親（`Booking`・`Theater`、いずれも
 * `CinemaScope` 適用済み）が担保する（4.8.6追記表「A-09の館スコープ」）。
 * `t_screenings` は `cinema_id` を持たないため、このPolicyに館の判定を置かない。
 */
class ScreeningPolicy
{
    /**
     * 上映回の画面自体の閲覧可否（4.8.2: `gate`ロールは権限を持たない）。
     * `AuthorizeAdminScreen`ミドルウェアはフルページロードのみを保護し
     * `/livewire/update`経由のアクション呼び出しには適用されないため（4.8.6追記表）、
     * 一覧取得・各アクションのたびにこのアビリティで判定する。
     */
    public function viewAny(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }

    public function create(Admin $admin): bool
    {
        return $this->canEditScreening($admin);
    }

    public function update(Admin $admin, Screening $screening): bool
    {
        return $this->canEditScreening($admin);
    }

    public function delete(Admin $admin, Screening $screening): bool
    {
        return $this->canEditScreening($admin);
    }

    /**
     * `create()` / `update()` / `delete()` と同一の判定基準を、対象
     *（`Screening`インスタンス）を持たない画面要素（新規登録ボタン・
     * 登録モーダルの表示可否）向けにクラスレベルのアビリティとして公開する
     *（A-04・A-06・A-08と同じ理由）。
     */
    public function updateAny(Admin $admin): bool
    {
        return $this->canEditScreening($admin);
    }

    private function canEditScreening(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }
}
