<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;

/**
 * 全館横断の参照可否を判定する。`CinemaScope`（13.4.1）がこのPolicyの結果で
 * スコープ適用の要否を分岐するため、役割の比較はここに集約する（13.4.2）。
 */
class CinemaPolicy
{
    /**
     * Laravel標準の `viewAny`（一覧の閲覧可否）と意味が異なる
     * （4.8.2が館マスタの閲覧を`cinema-admin`にも許可しているため）ため、
     * 「全館横断でスコープを外してよいか」であることが分かる名前にする。
     */
    public function viewAllCinemas(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }
}
