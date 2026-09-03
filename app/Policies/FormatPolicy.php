<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Format;

/**
 * 上映規格マスタ（A-06）の閲覧・編集可否を判定する（4.8.2）。
 * `m_formats`は`cinema_id`を持たないチェーン共通のマスタ（6.1.2）であり
 * `CinemaScope`（13.4.1）の対象外のため、役割のみで判定する。
 */
class FormatPolicy
{
    /**
     * 上映規格マスタ画面自体の閲覧可否（4.8.2: `gate`ロールは権限を持たない）。
     * `AuthorizeAdminScreen`ミドルウェアはフルページロードのみを保護し
     * `/livewire/update`経由のアクション呼び出しには適用されないため（4.8.6追記表）、
     * 一覧取得・各アクションのたびにこのアビリティで判定する。
     */
    public function viewAny(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }

    /**
     * 上映規格の編集可否（4.8.2: 編集は super-admin のみ）。編集できるのは
     * `default_surcharge` のみで、作成・削除・改名は 6.6 が規格を固定集合として
     * 列挙しているため画面の対象外（4.8.6追記表）。
     */
    public function update(Admin $admin, Format $format): bool
    {
        return $this->canEditFormat($admin);
    }

    /**
     * `update()`と同一の判定基準を、対象（`Format`インスタンス）を持たない
     * 画面要素（編集モーダルの表示可否）向けにクラスレベルのアビリティとして
     * 公開する（A-04の`TheaterPolicy::updateAny`と同じ理由）。
     */
    public function updateAny(Admin $admin): bool
    {
        return $this->canEditFormat($admin);
    }

    private function canEditFormat(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }
}
