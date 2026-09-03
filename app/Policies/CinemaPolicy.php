<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Cinema;

/**
 * 全館横断の参照可否、および館マスタ（A-03）の作成・編集可否を判定する。
 * `CinemaScope`（13.4.1）がこのPolicyの結果でスコープ適用の要否を分岐するため、
 * 役割の比較はここに集約する（13.4.2）。
 */
class CinemaPolicy
{
    /**
     * 館マスタ一覧・詳細の閲覧可否（4.8.2: `gate`ロールは館マスタの権限を持たない）。
     * `AuthorizeAdminScreen`ミドルウェアはフルページロードのみを保護し
     * `/livewire/update`経由のアクション呼び出しには適用されないため（4.8.6追記表）、
     * 一覧取得・詳細表示のたびにこのアビリティで判定する。
     */
    public function viewAny(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }

    /**
     * Laravel標準の `viewAny`（一覧の閲覧可否）と意味が異なる
     * （4.8.2が館マスタの閲覧を`cinema-admin`にも許可しているため）ため、
     * 「全館横断でスコープを外してよいか」であることが分かる名前にする。
     */
    public function viewAllCinemas(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }

    /**
     * 館マスタの新規作成可否（4.8.2: 作成・編集は super-admin のみ）。
     */
    public function create(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }

    /**
     * 館マスタの編集可否（4.8.2: 作成・編集は super-admin のみ）。
     */
    public function update(Admin $admin, Cinema $cinema): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }
}
