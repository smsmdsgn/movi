<?php

namespace App\Enums;

/**
 * 入場の取消（4.6.5）の1回の試行の結果。予約検索（A-11）から実行する。
 */
enum CheckInRevocation: string
{
    /** `checked_in_at` を null に戻した。 */
    case Revoked = 'revoked';

    /** 入場していない（または `paid` でない）。二度押し・別の端末での取消が先に済んだ場合を含む。 */
    case NotCheckedIn = 'not_checked_in';

    /** 4.6.5「期限」。上映回の終了時刻を過ぎた。 */
    case Ended = 'ended';

    /** 画面に示す文言のキー。 */
    public function messageKey(): string
    {
        return 'admin.reservation_search.revoke.'.$this->value;
    }
}
