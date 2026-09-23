<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Banner;

/**
 * バナー（A-13）の閲覧・作成・編集可否を判定する（4.8.2「バナー: `super-admin` は
 * 編集、`cinema-admin` は不可」／17.6-6「アップロード操作は `super-admin` に限定する」）。
 *
 * 画面への到達は `view-admin-screen` Gate（`AppServiceProvider`）も
 * `super-admin` に限っているが、あちらはフルページロードのみを保護する
 * （4.8.6追記表）。`/livewire/update` 経由のアクション呼び出しはこのPolicyが担う。
 *
 * 削除のアビリティは設けない（6.2「お知らせ・バナーは保持。公開期間終了後は
 * 非公開として残す」。A-12・A-14 と同じ判断。4.7.5追記表）。
 */
class BannerPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $this->canManageBanner($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->canManageBanner($admin);
    }

    public function update(Admin $admin, Banner $banner): bool
    {
        return $this->canManageBanner($admin);
    }

    /**
     * `create()` / `update()` と同一の判定基準を、対象（`Banner`インスタンス）を
     * 持たない画面要素（新規登録ボタン・登録モーダルの表示可否）向けに
     * クラスレベルのアビリティとして公開する（A-12 等と同じ理由）。
     */
    public function updateAny(Admin $admin): bool
    {
        return $this->canManageBanner($admin);
    }

    private function canManageBanner(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }
}
