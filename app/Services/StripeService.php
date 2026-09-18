<?php

namespace App\Services;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Stripe 連携（8.2）。テストモードのみで動作させる（2.5.1）。
 *
 * **カード情報は本サービスを通らない。** ブラウザが Stripe Elements で直接 Stripe へ
 * 送り、本システムが受け取るのはトークン化された PaymentMethod のID（`pm_...`）だけで
 * ある（17.3-1）。ID 以外のカード情報は保持しない（17.4.1）。
 *
 * 本工程（P-36、決済画面）で必要なのは、ブラウザが申告した PaymentMethod が実在する
 * カードであることの確認までである。PaymentIntent の作成・確認（Idempotency Key の
 * 付与を含む。17.3-3・4）と返金（4.4-4）は、予約確認（P-37）の実装時に本サービスへ
 * 追加する。
 *
 * `StripeClient` はコンテナから解決する（`AppServiceProvider`）。テストでは差し替え、
 * 実際の通信を行わない。キー未設定の環境では解決自体を行わず、`isConfigured()` に
 * よって画面が案内を出す（8.1 の `TmdbService` と同じ扱い）。
 */
class StripeService
{
    /**
     * PaymentMethod のIDとして受け付ける形式。
     *
     * **APIへ渡す前に必ず確かめる。** IDはURLのパスに連結されるため、`/` や `?` を
     * 含む文字列をそのまま渡すと別のエンドポイントへのリクエストを組み立てられる
     * （17.5.1「クライアントから送られた値を信用しない」）。
     *
     * 英数字に加えてアンダースコアを許す（Stripe のテスト用ID `pm_card_visa` 等がこの形式）。
     */
    public const string PAYMENT_METHOD_ID_REGEX = '#\Apm_[A-Za-z0-9_]{1,250}\z#';

    /**
     * PaymentIntent のIDとして受け付ける形式。`retrievePayment()` が用いる。
     *
     * 保存元は `t_reservations.stripe_payment_intent_id` だが、URLのパスへ連結する点は
     * PaymentMethod と変わらないため、同じ確認を行う。
     */
    public const string PAYMENT_INTENT_ID_REGEX = '#\Api_[A-Za-z0-9_]{1,250}\z#';

    /** 決済通貨（8.2。テストモードのみ）。JPY はゼロ小数通貨のため金額を100倍しない。 */
    public const string CURRENCY = 'jpy';

    private ?StripeClient $client = null;

    /**
     * 決済を提供できる状態か（15.1 の `STRIPE_KEY` / `STRIPE_SECRET`）。
     *
     * 公開可能キーが無ければブラウザが Elements を初期化できず、シークレットキーが
     * 無ければサーバー側の検証ができない。片方だけでは決済が成立しないため双方を見る。
     */
    public function isConfigured(): bool
    {
        return $this->publishableKey() !== '' && $this->secretKey() !== '';
    }

    /**
     * 公開可能キー（`pk_test_...`）。ブラウザへ渡す前提の値であり秘匿情報ではない（17.9-1）。
     */
    public function publishableKey(): string
    {
        $key = config('services.stripe.key');

        return is_string($key) ? trim($key) : '';
    }

    /**
     * ブラウザが申告した PaymentMethod が、実在するカードか（17.3-2 と同趣旨）。
     *
     * 申告されたIDをそのまま持ち越すと、誤りが判明するのは課金の時点（P-37）になる。
     * 形式・実在・種別をこの画面で確かめ、カードの入力し直しとして扱えるようにする。
     *
     * @throws StripeException 通信に失敗した場合（利用者の入力の誤りとは区別する）
     */
    public function isUsableCard(string $paymentMethodId): bool
    {
        if (preg_match(self::PAYMENT_METHOD_ID_REGEX, $paymentMethodId) !== 1) {
            return false;
        }

        try {
            $paymentMethod = $this->client()->paymentMethods->retrieve($paymentMethodId);
        } catch (InvalidRequestException) {
            // 存在しないID・他のアカウントのID・既に別の決済で使用済みのID。いずれも
            // 利用者から見れば「このカードでは進めない」であり、入力のやり直しで解消する。
            return false;
        } catch (ApiErrorException) {
            // 通信失敗・認証エラー・レート制限。例外メッセージはリクエスト内容を含むため
            // そのまま送出しない（17.9-1）。
            throw StripeException::requestFailed();
        }

        return $paymentMethod->type === 'card';
    }

    /**
     * カードに課金する（8.2 / 17.3）。作成と確認（confirm）を1回の呼び出しで行う。
     *
     * **金額はサーバーが算出した値のみを受け取る**（17.3-2）。`$idempotencyKey` は通信の
     * 再送による二重課金を防ぐ（17.3-4）。**同じキーで再送すると Stripe は最初の応答を
     * 返す**ため、カードを入れ直した再試行には別のキーを与えること。
     *
     * `use_stripe_sdk` を立てるのは、追加認証（3Dセキュア）をブラウザの
     * `handleNextAction()` で処理するため。`payment_method_types` をカードに限ることで、
     * 外部サイトへのリダイレクトを伴う支払方法が選ばれる経路を作らない（8.2 の【根拠】）。
     *
     * @param  array<string, string>  $metadata  Stripe 側の照合用。個人情報を含めない（17.4.3）
     *
     * @throws StripeException 通信に失敗した場合
     */
    public function chargeCard(int $amount, string $paymentMethodId, string $idempotencyKey, array $metadata = []): CardCharge
    {
        try {
            $intent = $this->client()->paymentIntents->create([
                'amount' => $amount,
                // JPY は最小単位が円であり、100倍しない（ゼロ小数通貨）。
                'currency' => self::CURRENCY,
                'payment_method' => $paymentMethodId,
                'payment_method_types' => ['card'],
                'confirm' => true,
                'use_stripe_sdk' => true,
                'metadata' => $metadata,
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (CardException $exception) {
            // カードの拒否。利用者の操作（別のカードの入力）で解消しうるため、
            // 通信の失敗とは区別する。
            return CardCharge::declined($exception->getError()?->payment_intent?->id);
        } catch (InvalidRequestException) {
            // 使用済み・存在しない PaymentMethod 等。同じく入力のやり直しへ倒す。
            return CardCharge::declined(null);
        } catch (ApiErrorException) {
            throw StripeException::requestFailed();
        }

        return $this->toCharge($intent);
    }

    /**
     * PaymentIntent を再取得する（8.2「決済結果の検証」/ 17.3-3）。
     *
     * **クライアントから通知された決済成功をそのまま信用しない。** 追加認証を終えた
     * 旨の通知を受けた後、本メソッドで取り直した `status` と金額で確定を判断する。
     *
     * @throws StripeException 通信に失敗した場合、およびIDの形式が不正な場合
     */
    public function retrievePayment(string $paymentIntentId): CardCharge
    {
        if (preg_match(self::PAYMENT_INTENT_ID_REGEX, $paymentIntentId) !== 1) {
            throw StripeException::requestFailed();
        }

        try {
            $intent = $this->client()->paymentIntents->retrieve($paymentIntentId);
        } catch (ApiErrorException) {
            throw StripeException::requestFailed();
        }

        return $this->toCharge($intent);
    }

    /**
     * 返金する（4.4-4 / 17.3-5）。
     *
     * 呼び出し側は予約1件につき1回のみ実行すること（`t_reservations.refunded_at`）。
     * 本メソッドにも `$idempotencyKey` を与え、通信の再送で二重に返金しない。
     *
     * @throws StripeException 返金できなかった場合（運用で追う必要があるため握り潰さない）
     */
    public function refund(string $paymentIntentId, string $idempotencyKey): void
    {
        try {
            $this->client()->refunds->create(
                ['payment_intent' => $paymentIntentId],
                ['idempotency_key' => $idempotencyKey],
            );
        } catch (ApiErrorException) {
            throw StripeException::refundFailed();
        }
    }

    /**
     * 追加認証（3Dセキュア）の途中で確定できなくなった PaymentIntent を取り消す。
     *
     * 課金は成立していないため返金ではなく取り消しとする。失敗しても利用者の操作には
     * 影響しないため、例外にせず黙って戻る（Stripe 側は未確定のまま自動で失効する）。
     */
    public function cancelPayment(string $paymentIntentId): bool
    {
        try {
            $this->client()->paymentIntents->cancel($paymentIntentId);
        } catch (ApiErrorException) {
            // 取り消せなくても、確定していない PaymentIntent は課金にならない。
            // **成否は返す。** 呼び出し側は「取り消せた」場合にのみ、予約から
            // PaymentIntent のIDを外す（10章 B-02 の除外条件）。
            return false;
        }

        return true;
    }

    private function toCharge(PaymentIntent $intent): CardCharge
    {
        return CardCharge::fromIntent(
            $intent->id,
            (string) $intent->status,
            (int) $intent->amount,
            is_string($intent->client_secret) ? $intent->client_secret : null,
        );
    }

    /**
     * @throws StripeException キーが未設定の場合
     */
    private function client(): StripeClient
    {
        if ($this->secretKey() === '') {
            throw StripeException::notConfigured();
        }

        return $this->client ??= app(StripeClient::class);
    }

    private function secretKey(): string
    {
        $secret = config('services.stripe.secret');

        return is_string($secret) ? trim($secret) : '';
    }
}
