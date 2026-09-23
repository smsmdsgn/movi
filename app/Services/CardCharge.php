<?php

namespace App\Services;

/**
 * Stripe への課金1回分の結果（8.2）。
 *
 * **Stripe のオブジェクトを呼び出し側へ渡さない。** `ReservationService` が必要とするのは
 * 「確定してよいか」「追加認証へ回すか」「失敗として扱うか」の3択と、検証に要する
 * PaymentIntent のID・状態・金額だけである（17.3-3）。SDK の型を予約確定の側へ広げると、
 * 決済事業者を差し替えられない構造になる。
 *
 * `clientSecret` は追加認証（3Dセキュア）のときのみ持つ。ブラウザの `handleNextAction()`
 * に渡す値であり、公開可能キーと組で用いる前提の値のため秘匿情報ではない。
 */
final readonly class CardCharge
{
    /** 課金が完了した状態（Stripe の PaymentIntent の `status`）。 */
    public const string STATUS_SUCCEEDED = 'succeeded';

    /** 追加認証（3Dセキュア等）が必要な状態。 */
    public const string STATUS_REQUIRES_ACTION = 'requires_action';

    /**
     * 取り消し済みの状態。**課金は成立していない。**
     *
     * B-02（10章）が再度 `cancel` を投げないために要る。取り消し済みの PaymentIntent へ
     * `cancel` を送ると Stripe はエラーを返すため、**取り消せなかったものと区別できずに
     * 恒久的に `pending` が残る**（4.3.19）。
     */
    public const string STATUS_CANCELED = 'canceled';

    private function __construct(
        public string $paymentIntentId,
        public string $status,
        public int $amount,
        public ?string $clientSecret = null,
    ) {}

    /**
     * Stripe が返した PaymentIntent から組み立てる。
     */
    public static function fromIntent(string $paymentIntentId, string $status, int $amount, ?string $clientSecret): self
    {
        return new self($paymentIntentId, $status, $amount, $clientSecret);
    }

    /**
     * カードが拒否された場合（`CardException`）。PaymentIntent が作られていれば
     * そのIDを持つ（返金は不要だが、問い合わせの追跡に用いる）。
     */
    public static function declined(?string $paymentIntentId): self
    {
        return new self($paymentIntentId ?? '', 'requires_payment_method', 0);
    }

    /**
     * 課金が成立し、**要求した金額と一致している**か（17.3-3）。
     *
     * 金額を引数で受け取って突き合わせる。呼び出し側が `status` だけを見て確定すると、
     * 改ざん・取り違えで異なる金額の決済を成功として扱いうる。
     */
    public function isSettled(int $expectedAmount): bool
    {
        return $this->status === self::STATUS_SUCCEEDED && $this->amount === $expectedAmount;
    }

    /**
     * 追加認証（3Dセキュア）が必要か。ブラウザで認証を終えたのち、サーバーが
     * PaymentIntent を再取得して検証する（8.2）。
     */
    public function requiresAction(): bool
    {
        return $this->status === self::STATUS_REQUIRES_ACTION && $this->clientSecret !== null;
    }
}
