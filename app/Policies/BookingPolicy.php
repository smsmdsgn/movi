<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Booking;

/**
 * 上映編成（A-08）の閲覧・作成・編集可否を判定する（4.8.2）。
 * `Booking`は`CinemaScope`（13.4.1）で自館以外を除外済みのため、
 * ここでの役割比較は「どこまで操作できるか」のみを扱う（A-04と同じ）。
 */
class BookingPolicy
{
    /**
     * 上映編成の画面自体の閲覧可否（4.8.2: `gate`ロールは権限を持たない）。
     * `AuthorizeAdminScreen`ミドルウェアはフルページロードのみを保護し
     * `/livewire/update`経由のアクション呼び出しには適用されないため（4.8.6追記表）、
     * 一覧取得・各アクションのたびにこのアビリティで判定する。
     */
    public function viewAny(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }

    /**
     * 上映編成の新規登録可否（4.8.2: 作成・編集は super-admin のみ。
     * `cinema-admin` に残る権限は上映回（A-09）であり、編成は本部が組む）。
     */
    public function create(Admin $admin): bool
    {
        return $this->canEditBooking($admin);
    }

    /**
     * 上映編成の編集可否（4.8.2: 作成・編集は super-admin のみ）。
     */
    public function update(Admin $admin, Booking $booking): bool
    {
        return $this->canEditBooking($admin);
    }

    /**
     * `create()` / `update()` と同一の判定基準を、対象（`Booking`インスタンス）を
     * 持たない画面要素（新規登録ボタン・編集モーダルの表示可否）向けに
     * クラスレベルのアビリティとして公開する（A-04・A-06と同じ理由）。
     */
    public function updateAny(Admin $admin): bool
    {
        return $this->canEditBooking($admin);
    }

    private function canEditBooking(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }
}
