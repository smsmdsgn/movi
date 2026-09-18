<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Facades\Session;

/**
 * 直前に確定した予約の記録（P-38 の到達可否、17.2.1 / 12章 旧残課題34）。
 *
 * 予約完了画面のURLは予約番号だけを含み（`/reservations/{no}/complete`）、予約番号は
 * 8桁の数字であるため推測できる（4.3.5）。**到達可否は推測困難性に依らず認可で
 * 担保する**（17.2.1-1）ため、会員は所有者（`user_id`）の一致で、非会員は
 * 「この画面を開いたブラウザが、その予約を確定させた当人であること」で判定する。
 * 判定そのものは `ReservationPolicy::view()` が行い、本クラスは後者の材料を持つ。
 *
 * ```
 * session('completed_reservations') = [int, ...]  // 予約ID。新しいものを先頭に置く
 * ```
 *
 * **`ReservationDraft` とは別のキーに置く。** 確定した時点で下書きは破棄される
 * （`ReservationDraft::clear()`）が、完了画面はその後に開かれる。
 *
 * **保持するのは予約IDのみ**とし、件数に上限を設ける。1回のセッションで続けて複数の
 * 予約を取る利用者が、前の予約の完了画面（予約確定メールの控えを兼ねる）へ戻れる
 * 程度で足りる。セッションの寿命（既定120分、`config/session.php`）を越えれば
 * 会員以外は到達できなくなり、以後は予約照会（P-07、4.3.5）が受け持つ。
 */
class CompletedReservations
{
    private const string SESSION_KEY = 'completed_reservations';

    /**
     * 記録する件数の上限。
     */
    private const int MAX_ENTRIES = 5;

    /**
     * 確定した予約を記録する（P-37 の確定後）。
     */
    public function remember(Reservation $reservation): void
    {
        $ids = array_values(array_filter(
            $this->ids(),
            fn (int $id): bool => $id !== $reservation->id,
        ));

        array_unshift($ids, $reservation->id);

        Session::put(self::SESSION_KEY, array_slice($ids, 0, self::MAX_ENTRIES));
    }

    /**
     * このブラウザが確定させた予約か。
     */
    public function includes(Reservation $reservation): bool
    {
        return in_array($reservation->id, $this->ids(), strict: true);
    }

    /**
     * 記録済みの予約ID。形式が想定と異なる値は捨てる。
     *
     * セッションの中身は本クラスだけが書くが、寿命をまたいだ再開や後続の工程での
     * 構造変更によって古い形式が残りうる（`ReservationDraft::current()` と同じ理由）。
     *
     * @return array<int, int>
     */
    private function ids(): array
    {
        $ids = Session::get(self::SESSION_KEY);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, fn (mixed $id): bool => is_int($id)));
    }
}
