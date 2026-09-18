<?php

namespace App\Services;

use RuntimeException;

/**
 * Stripe 連携（8.2）の失敗を表す例外。
 *
 * 例外メッセージそのものを画面に出さず、`$messageKey` の言語ファイルキーを
 * 画面側で `__()` して表示する（20.1）。Stripe SDK の例外はリクエストの内容や
 * 応答本文をメッセージに含むため、そのまま送出せず本例外へ置き換える（17.9-1）。
 *
 * `app/Exceptions/` を新設せず `app/Services/` に置く（`TmdbException` と同じ扱い。
 * 13.1 のディレクトリ構成に無いベースディレクトリを増やさないため）。
 */
class StripeException extends RuntimeException
{
    private function __construct(public readonly string $messageKey, string $message)
    {
        parent::__construct($message);
    }

    /** APIキー（`STRIPE_KEY` / `STRIPE_SECRET`、15.1）が未設定。 */
    public static function notConfigured(): self
    {
        return new self('front.reservation.payment.errors.unavailable', 'Stripe API keys are not configured.');
    }

    /** 通信失敗、または Stripe がエラーを返した。文言は 7.17「決済失敗」を用いる。 */
    public static function requestFailed(): self
    {
        return new self('front.reservation.errors.payment_failed', 'The request to Stripe failed.');
    }

    /**
     * 返金に失敗した（4.4-4 / 17.3-5）。
     *
     * **課金が成立したまま予約が確定していない状態**を指すため、他の失敗と区別する。
     * 利用者には座席の取り直しを案内しつつ、運用側での追跡が要る。
     */
    public static function refundFailed(): self
    {
        return new self('front.reservation.errors.refund_failed', 'The refund request to Stripe failed.');
    }
}
