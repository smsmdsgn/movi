<?php

namespace App\Enums;

/**
 * 入場ゲート（A-16）の1回の判定の結果（4.6.3 / 4.6.4）。
 *
 * **拒否は例外で表現しない**（`CancellationOutcome` と同じ方針）。ゲートでは拒否も
 * 通常の経路であり、画面全体を赤にして理由を示すだけである。
 */
enum EntryOutcome: string
{
    /** 入場を記録した（`checked_in_at` を設定した）。 */
    case Admitted = 'admitted';

    /** 入場コード・予約番号に該当する予約が無い。 */
    case NotFound = 'not_found';

    /** 4.6.4-1。予約は在るが、端末の館の予約ではない。 */
    case OtherCinema = 'other_cinema';

    /** 4.6.4-2。キャンセル済みの予約。 */
    case Cancelled = 'cancelled';

    /** 4.6.4-2。確定していない予約（`pending` / `expired`）。予約番号での手入力でのみ到達する。 */
    case NotConfirmed = 'not_confirmed';

    /** 4.6.4-3。上映開始の60分前より早い。 */
    case TooEarly = 'too_early';

    /** 4.6.4-3。上映終了時刻を経過した。 */
    case Ended = 'ended';

    /** 4.6.4-4。入場済み。 */
    case AlreadyCheckedIn = 'already_checked_in';

    public function isAdmitted(): bool
    {
        return $this === self::Admitted;
    }

    /**
     * 予約の内容（予約者名・上映開始時刻）を画面に示してよいか。
     *
     * **他館の予約は示さない。** `cinema-admin`・`gate` は所属館のデータのみを取得できる
     * （17.2.1-3）。4.6.3 は拒否時に予約者名を表示すると定めるが、館の不一致では
     * 理由だけを示す。
     */
    public function revealsReservation(): bool
    {
        return ! in_array($this, [self::NotFound, self::OtherCinema], true);
    }

    /** 画面に示す文言のキー。 */
    public function messageKey(): string
    {
        return 'admin.gate.outcome.'.$this->value;
    }
}
