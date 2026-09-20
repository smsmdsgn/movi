<?php

namespace App\Enums;

/**
 * 予約キャンセルの試行の結果（4.4 / 13.4.7）。画面（P-07・将来の P-06）はこの3つで分岐する。
 *
 * 文字列値を持たせるのは、Livewire の公開プロパティに載せずともログ・テストで
 * 読める形にしておくため（13.3。`PaymentOutcome` と同じ扱い）。
 */
enum CancellationOutcome: string
{
    /** キャンセルが成立し、返金も済んだ（または返金の要らない0円の予約だった）。 */
    case Cancelled = 'cancelled';

    /**
     * **キャンセルは成立したが、返金が完了していない。**
     *
     * 座席は再販可能に戻っており、予約を `paid` へ戻すことはしない（4.3.18）。
     * 利用者には劇場への連絡を案内し、運用側は `stripe_payment_intent_id` と
     * `refunded_at IS NULL` で追跡する（17.3-5）。
     */
    case CancelledWithoutRefund = 'cancelled_without_refund';

    /**
     * 4.4 の条件を満たさないため、キャンセルしなかった。
     *
     * 期限切れ（上映開始20分前を過ぎた）・入場済み・既にキャンセル済みのいずれか。
     * **課金にも座席にも触れていない。**
     */
    case Rejected = 'rejected';
}
