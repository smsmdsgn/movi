<?php

namespace App\Services;

use App\Enums\CancellationOutcome;
use App\Models\Reservation;

/**
 * `ReservationService::cancel()` の1回の試行の結果（4.4 / 13.4.7）。
 *
 * **成否を例外で表現しない**（`PaymentAttempt` と同じ方針）。期限切れも入場済みも
 * 通常の経路であり、例外にすると呼び出し側が正常系を catch で書くことになる。
 *
 * **`messageKey` はどの結果でも必ず入る。** 拒否された理由（`Rejected`）、返金が未完了で
 * ある旨（`CancelledWithoutRefund`）、成立した旨（`Cancelled`）のいずれかであり、
 * **文言の出し分けは呼び出し側ではなくここで確定する**。呼び出し側が「返金したか」を
 * 金額から推し量ると、推し量り方を画面ごとに書くことになる（工程6 の P-06 が2つ目の
 * 呼び出し側になる。4.3.18）。
 */
final readonly class Cancellation
{
    private function __construct(
        public CancellationOutcome $outcome,
        public string $messageKey,
    ) {}

    /**
     * キャンセルが成立し、返金も済んだ（または返金の要らない0円の予約だった）。
     *
     * **確定後の予約は載せない**（`PaymentAttempt::confirmed()` と異なる点）。呼び出し側は
     * 同じ画面を描き直すため DB から読み直しており、渡しても使い道が無い。必要になった
     * 時点で足すこと。
     */
    public static function done(Reservation $reservation, string $messageKey): self
    {
        return new self(CancellationOutcome::Cancelled, $messageKey);
    }

    /**
     * キャンセルは成立したが返金が完了していない。
     *
     * **予約を `paid` へ戻さない。** 座席は既に再販可能へ戻しており、戻す操作は
     * 他者が取得済みの座席を奪い返しうる（4.3.18）。
     */
    public static function refundPending(Reservation $reservation, string $messageKey): self
    {
        return new self(CancellationOutcome::CancelledWithoutRefund, $messageKey);
    }

    /** 4.4 の条件を満たさない。課金にも座席にも触れていない。 */
    public static function rejected(string $messageKey): self
    {
        return new self(CancellationOutcome::Rejected, $messageKey);
    }
}
