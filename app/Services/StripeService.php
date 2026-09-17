<?php

namespace App\Services;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
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
