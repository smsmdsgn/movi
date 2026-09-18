<?php

namespace App\Services;

use App\Enums\PaymentOutcome;
use App\Models\Reservation;

/**
 * `ReservationService` の1回の試行の結果（13.4.7）。
 *
 * 画面が必要とするのは「次にどこへ進めるか」と、そのために持ち回る値だけである。
 * **成否を例外で表現しない。** カードの拒否も追加認証も通常の経路であり、
 * 例外にすると呼び出し側が正常系を catch で書くことになる。
 *
 * `clientSecret` は追加認証のときのみ持つ（`CardCharge` と同じ扱い）。
 */
final readonly class PaymentAttempt
{
    private function __construct(
        public PaymentOutcome $outcome,
        public ?Reservation $reservation = null,
        public ?string $clientSecret = null,
        public ?string $messageKey = null,
    ) {}

    public static function confirmed(Reservation $reservation): self
    {
        return new self(PaymentOutcome::Confirmed, $reservation);
    }

    public static function requiresAuthentication(Reservation $reservation, string $clientSecret): self
    {
        return new self(PaymentOutcome::RequiresAuthentication, $reservation, $clientSecret);
    }

    /**
     * 決済に失敗した。
     *
     * `$reservation` を載せるのは、**同じ予約で再試行すべき場合**に限る。通信の失敗は
     * 課金が成立したかどうかが不明であり、予約を作り直すと冪等キーが変わって二重課金に
     * なりうる（17.3-4）。同じ予約で送り直せば、Stripe は最初の応答を返す。
     *
     * カードが拒否された場合は逆に**載せない**。同じ冪等キーでは Stripe が最初の拒否を
     * 返し続けるため、カードを入れ直した再試行が成立しない。
     */
    public static function failed(string $messageKey, ?Reservation $reservation = null): self
    {
        return new self(PaymentOutcome::Failed, $reservation, messageKey: $messageKey);
    }

    /**
     * 課金の成立後に座席を確保できなかった場合。返金は本メソッドを返す前に済ませる。
     */
    public static function seatsUnavailable(string $messageKey): self
    {
        return new self(PaymentOutcome::SeatsUnavailable, messageKey: $messageKey);
    }
}
