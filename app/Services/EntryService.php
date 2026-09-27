<?php

namespace App\Services;

use App\Enums\CheckInRevocation;
use App\Enums\EntryOutcome;
use App\Enums\ReservationStatus;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\Screening;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * 入場の記録（4.6.3 / 4.6.4）と取消（4.6.5）。
 *
 * **権限の判定は呼び出し側が行う**（`ReservationPolicy::checkIn` / `revokeCheckIn`）。
 * 本クラスは「どの館の端末か」を `Cinema` として受け取り、館の一致（4.6.4-1）だけを見る。
 * 端末の館を決めるのは呼び出し側（A-16。`Cinema::visibleTo()` で可視範囲に限る）である。
 *
 * **排他は予約行の `lockForUpdate()` で行い、判定をロックの内側でやり直す。** 同じQRを
 * 2台の端末が同時に読んでも記録されるのは1回だけであり、キャンセル
 * （`ReservationService::cancel()`。同じ予約行をロックして `checked_in_at` を確かめる）と
 * 競合しても「入場済みの予約がキャンセルされる」「キャンセル済みの予約に入場が記録される」の
 * いずれも起こらない。**掴むのは予約行1つだけ**であり、上映回 → 予約の順で掴む `cancel()`
 * （4.3.8）と循環待ちを作らない。
 *
 * **更新は Eloquent の `save()` で行う**（5.5-1・2。入場の記録と取消は管理操作であり、
 * 操作ログを追加する際にモデルイベントで捕捉できるようにする）。
 */
class EntryService
{
    /** 入場コードの形式（4.6.2-1。`ReservationService` が `Str::random(32)` で採番する）。 */
    private const string ENTRY_CODE_PATTERN = '/\A[A-Za-z0-9]{32}\z/';

    /**
     * QRコードから読み取った入場コードで入場を処理する（4.6.3 動作2〜4）。
     *
     * 形式に合わない文字列（別のQRコードをかざした場合など）は、DBを引かずに該当なしとする。
     */
    public function admitByEntryCode(Cinema $cinema, string $entryCode, ?CarbonImmutable $now = null): EntryResult
    {
        if (preg_match(self::ENTRY_CODE_PATTERN, $entryCode) !== 1) {
            return EntryResult::unidentified(EntryOutcome::NotFound);
        }

        return $this->admit($cinema, Reservation::query()->where('entry_code', $entryCode), $now);
    }

    /**
     * 予約番号で入場を処理する（4.6.3「手入力モード」）。ハイフンの有無を問わない（4.3.5）。
     * 形式の検証は呼び出し側（入力欄のバリデーション）が行う。
     */
    public function admitByReservationNo(Cinema $cinema, string $reservationNo, ?CarbonImmutable $now = null): EntryResult
    {
        return $this->admit(
            $cinema,
            Reservation::query()->where('reservation_no', str_replace('-', '', $reservationNo)),
            $now,
        );
    }

    /**
     * 入場を取り消す（4.6.5）。`checked_in_at` を null に戻す。
     *
     * **館の範囲は呼び出し側が担保する**（A-11 は `CinemaScope` 経由で可視の予約だけを渡す）。
     * 取消の後は、顧客によるキャンセル（4.4-5 が入場済みを拒む）も再び可能になる。
     */
    public function revokeCheckIn(Reservation $reservation, ?CarbonImmutable $now = null): CheckInRevocation
    {
        $now ??= Date::now();

        return DB::transaction(function () use ($reservation, $now): CheckInRevocation {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== ReservationStatus::Paid || ! $locked->isCheckedIn()) {
                return CheckInRevocation::NotCheckedIn;
            }

            $screening = Screening::query()->find($locked->screening_id);

            if ($screening === null || $screening->hasEnded($now)) {
                return CheckInRevocation::Ended;
            }

            $locked->checked_in_at = null;
            $locked->save();

            return CheckInRevocation::Revoked;
        });
    }

    /**
     * @param  Builder<Reservation>  $match  入場コードまたは予約番号で1件に絞るクエリ
     */
    private function admit(Cinema $cinema, Builder $match, ?CarbonImmutable $now): EntryResult
    {
        $now ??= Date::now();

        // 4.6.4-1。館の一致を先に見る。端末の館の上映回に限って引き、引けなければ
        // 館を問わずに存在だけを確かめる（他館の予約の内容は読み込まない。17.2.1-3）。
        // 表示用の関連もここで読む（ロックの外。状態の判定には使わない）。
        $reservation = (clone $match)
            ->whereIn(
                'screening_id',
                Screening::query()
                    ->whereHas('booking', fn (Builder $booking) => $booking->where('cinema_id', $cinema->id))
                    ->select('id'),
            )
            ->with([
                'user:id,name',
                'seats.seat',
                'screening.theater',
                'screening.booking.movie',
            ])
            ->first();

        if ($reservation === null) {
            return EntryResult::unidentified(
                $match->exists() ? EntryOutcome::OtherCinema : EntryOutcome::NotFound
            );
        }

        $rejection = DB::transaction(function () use ($reservation, $now): ?EntryOutcome {
            // **トランザクションの最初の文をロック付きの読み取りにする**（4.3.8。平文の
            // SELECT が先に走ると、REPEATABLE READ のスナップショットが古い値で確定する）。
            // 直前に引けた行であり、見つからないことは想定しない（予約を削除する経路は無い）。
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            // 上映回は入場の判定の間に変わらない（有効な予約を持つ上映回は A-09 が編集を拒む。
            // 4.3.16）ため、ロックの外で読んだものを使う。
            $locked->setRelation('screening', $reservation->screening);

            $rejection = $this->rejectionFor($locked, $now);

            if ($rejection === null) {
                $locked->checked_in_at = $now;
                $locked->save();
            }

            // 表示する予約へ、ロックの内側で読んだ状態を写す（判定の根拠と表示を揃える）。
            $reservation->status = $locked->status;
            $reservation->checked_in_at = $locked->checked_in_at;
            $reservation->syncOriginal();

            return $rejection;
        });

        return $rejection === null
            ? EntryResult::admitted($reservation)
            : EntryResult::rejected($rejection, $reservation);
    }

    /**
     * 4.6.4-2〜4 のうち最初に該当した拒否理由。該当しなければ null（入場可）。
     * 呼び出し側が `screening` を読み込んでおくこと。
     */
    private function rejectionFor(Reservation $reservation, CarbonImmutable $now): ?EntryOutcome
    {
        if ($reservation->status === ReservationStatus::Cancelled) {
            return EntryOutcome::Cancelled;
        }

        if ($reservation->status !== ReservationStatus::Paid) {
            return EntryOutcome::NotConfirmed;
        }

        if ($reservation->screening->isBeforeEntryOpens($now)) {
            return EntryOutcome::TooEarly;
        }

        if ($reservation->screening->hasEnded($now)) {
            return EntryOutcome::Ended;
        }

        if ($reservation->isCheckedIn()) {
            return EntryOutcome::AlreadyCheckedIn;
        }

        return null;
    }
}
