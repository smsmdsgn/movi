<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Theater;

/**
 * シアター・座席管理（A-04）の閲覧・編集可否を判定する（4.8.2）。
 * `Theater`は既に`CinemaScope`（13.4.1）で自館以外を除外しているため、
 * ここでの役割比較は「同じ館内でどこまで操作できるか」のみを扱う。
 */
class TheaterPolicy
{
    /**
     * シアター・座席管理画面自体の閲覧可否（4.8.2: `gate`ロールは権限を持たない）。
     * `AuthorizeAdminScreen`ミドルウェアはフルページロードのみを保護し
     * `/livewire/update`経由のアクション呼び出しには適用されないため（4.8.6追記表）、
     * 一覧取得・各アクションのたびにこのアビリティで判定する。
     */
    public function viewAny(Admin $admin): bool
    {
        return $this->isNotGate($admin);
    }

    /**
     * シアター基本情報（名称・番号）の編集可否（4.8.2: 作成・編集は super-admin のみ）。
     */
    public function update(Admin $admin, Theater $theater): bool
    {
        return $this->canEditTheaterBasicInfo($admin);
    }

    /**
     * `update()`と同一の判定基準を、対象（`Theater`インスタンス）を持たない
     * 画面要素（編集モーダルの表示可否）向けにクラスレベルのアビリティとして
     * 公開する。判定条件自体は`canEditTheaterBasicInfo()`の1箇所のみに書く。
     */
    public function updateAny(Admin $admin): bool
    {
        return $this->canEditTheaterBasicInfo($admin);
    }

    /**
     * 座席の有効／無効切替の可否（4.8.2: super-adminは全館、cinema-adminは自館のみ）。
     * `$theater`は`CinemaScope`を経由して取得済みである前提のため、
     * ここでは役割のみを見ればよい（`cinema-admin`が他館の`Theater`を
     * 渡すことはfindOrFail時点で失敗する）。
     */
    public function toggleSeats(Admin $admin, Theater $theater): bool
    {
        return $this->isNotGate($admin);
    }

    private function canEditTheaterBasicInfo(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }

    private function isNotGate(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }
}
