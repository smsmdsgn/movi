<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;

/**
 * 管理者アカウント管理（A-14）とパスワード変更（A-15）の権限を判定する。
 * 4.8.2 は管理者アカウント管理を `super-admin` 限定とし、`cinema-admin`・`gate` は不可。
 * 自分自身のパスワード変更のみ `cinema-admin` にも許可する（`updateOwnPassword`）。
 *
 * `AuthorizeAdminScreen` ミドルウェアはフルページロードのみを保護し
 * `/livewire/update` 経由のアクション呼び出しには適用されないため（4.8.6追記表）、
 * 一覧取得・作成・更新のたびにこのPolicyで判定する。
 */
class AdminPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $this->isActiveSuperAdmin($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->isActiveSuperAdmin($admin);
    }

    public function update(Admin $admin, Admin $target): bool
    {
        return $this->isActiveSuperAdmin($admin);
    }

    /**
     * 役割および有効／無効の変更可否。**自分自身に対しては許可しない。**
     *
     * 4.8.4-4 がメールを起点とする認証導線（招待・リセットリンク）を持たないと
     * 定めているため、自らを無効化した場合や `super-admin` 以外へ降格した場合に
     * 復旧する手段が無い。氏名・ログインID・パスワードの変更（`update`）は
     * 締め出しに繋がらないため、このアビリティとは分けている。
     */
    public function manageAccess(Admin $admin, Admin $target): bool
    {
        return $this->isActiveSuperAdmin($admin) && $admin->id !== $target->id;
    }

    /**
     * 自分自身のパスワードを変更できるか（A-15）。対象の選択を伴わないため
     * クラスレベルのアビリティとして判定する。
     *
     * 4.8.2 は「パスワード変更（自分自身）」を `super-admin`・`cinema-admin` の
     * 双方に許可する。`gate` は入場確認以外の操作を行えない（17.1.3）ため除外する。
     * `view-admin-screen` Gate と同じ結論だが、同Gateを適用する
     * `AuthorizeAdminScreen` はフルページロードのみを保護するため、
     * `/livewire/update` 経由の `save()` はこのアビリティで判定する。
     */
    public function updateOwnPassword(Admin $admin): bool
    {
        return $admin->is_active && $admin->role !== AdminRole::Gate;
    }

    /**
     * 操作者自身が**有効な** `super-admin` であること。
     *
     * `is_active` はログイン時にしか評価されない（`EnsureAdminIsActive` の説明を参照）。
     * 同ミドルウェアがセッションを打ち切るため通常はここへ到達しないが、
     * **無効化された管理者どうしが相互に無効化して有効な `super-admin` を0人にする経路は
     * 復旧不能**（4.8.4-4）であるため、Policy 側でも操作者の有効性を確認する。
     */
    private function isActiveSuperAdmin(Admin $admin): bool
    {
        return $admin->is_active && $admin->role === AdminRole::SuperAdmin;
    }
}
