<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\TicketType;

/**
 * 券種・料金マスタ（A-07）の閲覧・編集可否を判定する（4.8.2）。
 * `m_ticket_types`は`cinema_id`を持たないチェーン共通のマスタであり
 * （6.5「館ごとの料金差は設けず、全館共通とする」）`CinemaScope`（13.4.1）の
 * 対象外のため、役割のみで判定する。
 */
class TicketTypePolicy
{
    /**
     * 券種・料金マスタ画面自体の閲覧可否（4.8.2: `gate`ロールは権限を持たない）。
     * `AuthorizeAdminScreen`ミドルウェアはフルページロードのみを保護し
     * `/livewire/update`経由のアクション呼び出しには適用されないため（4.8.6追記表）、
     * 一覧取得・各アクションのたびにこのアビリティで判定する。
     */
    public function viewAny(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }

    /**
     * 券種の編集可否（4.8.2: 編集は super-admin のみ）。編集できるのは
     * `price` と `condition` のみで、作成・削除・改名・表示順の変更は
     * 6.5.1 が券種を固定集合として列挙しているため画面の対象外（4.8.6追記表）。
     */
    public function update(Admin $admin, TicketType $ticketType): bool
    {
        return $this->canEditTicketType($admin);
    }

    /**
     * `update()`と同一の判定基準を、対象（`TicketType`インスタンス）を持たない
     * 画面要素（編集モーダルの表示可否）向けにクラスレベルのアビリティとして
     * 公開する（A-06の`FormatPolicy::updateAny`と同じ理由）。
     */
    public function updateAny(Admin $admin): bool
    {
        return $this->canEditTicketType($admin);
    }

    private function canEditTicketType(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }
}
