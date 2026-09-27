<?php

namespace App\Services;

use App\Enums\EntryOutcome;
use App\Models\Reservation;
use LogicException;

/**
 * 入場ゲート（A-16）の1回の判定の結果（4.6.3）。
 *
 * **`reservation` は `EntryOutcome::revealsReservation()` が真のときだけ入る。**
 * 他館の予約（17.2.1-3）と該当なしでは null とし、呼び出し側が誤って表示できないようにする。
 * 生成時に結果と予約の有無の組み合わせを検査する（取り違えを実装ミスとして止める）。
 * 入る場合は `user` / `seats.seat` / `screening.theater` / `screening.booking.movie` を
 * 読み込み済みである（表示項目 4.6.3。`preventLazyLoading`）。
 */
final readonly class EntryResult
{
    private function __construct(
        public EntryOutcome $outcome,
        public ?Reservation $reservation,
    ) {
        if ($outcome->revealsReservation() !== ($reservation !== null)) {
            throw new LogicException("入場の判定結果（{$outcome->value}）と予約の有無が一致しない。");
        }
    }

    public static function admitted(Reservation $reservation): self
    {
        return new self(EntryOutcome::Admitted, $reservation);
    }

    /** 予約を特定できなかった（該当なし・他館）。 */
    public static function unidentified(EntryOutcome $outcome): self
    {
        return new self($outcome, null);
    }

    public static function rejected(EntryOutcome $outcome, Reservation $reservation): self
    {
        return new self($outcome, $reservation);
    }
}
