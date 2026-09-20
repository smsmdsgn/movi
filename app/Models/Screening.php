<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property int $booking_id
 * @property int $theater_id
 * @property int|null $created_by_admin_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['booking_id', 'theater_id', 'starts_at', 'ends_at'])]
class Screening extends Model
{
    protected $table = 't_screenings';

    /**
     * 予告編の時間（分）。終了時刻の自動計算に用いる（4.8.3-3）。
     * A-09（登録画面）と `ScreeningSeeder`（`SeedConfig`）の双方がこれを参照する。
     */
    public const int TRAILER_MINUTES = 15;

    /** 同一シアターの上映回どうしに空ける最小間隔（分。清掃・入替時間、4.8.3-4）。 */
    public const int INTERVAL_MINUTES = 30;

    /**
     * 販売開始は上映日の何日前か（4.2.2-5 / 4.3.1「販売期間」）。当日0:00から販売する。
     * 表示（`ScheduleService`）と座席ロックの取得（`SeatLockService`）の双方が本定数を参照し、
     * 販売期間の規則を1箇所に持つ。
     */
    public const int SALES_START_DAYS_BEFORE = 3;

    /**
     * キャンセルの受付期限は上映開始の何分前までか（4.4-1）。
     * 導線の出し分け（P-07・将来の P-06）と `ReservationService::cancel()` の双方が
     * 本定数と `acceptsCancellationAt()` を参照し、期限の規則を1箇所に持つ
     * （`SALES_START_DAYS_BEFORE` と `isOnSale()` の関係と同じ扱い）。
     */
    public const int CANCEL_DEADLINE_MINUTES = 20;

    protected function casts(): array
    {
        return [
            // `SeatLockService::acquire()` が座席のシアターと厳密比較するため、
            // PDO の返す型に依存しないよう明示的にキャストする。
            'theater_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * 販売開始日時（上映日の3日前 0:00、4.3.1）。
     */
    public function salesStartAt(): CarbonImmutable
    {
        return $this->starts_at->startOfDay()->subDays(self::SALES_START_DAYS_BEFORE);
    }

    /**
     * 販売開始前か（4.2.2-5）。上映スケジュール表では「販売前」として非活性表示にする。
     */
    public function isBeforeSale(?CarbonImmutable $now = null): bool
    {
        return $this->salesStartAt()->isAfter($now ?? Date::now());
    }

    /**
     * 販売期間内か（4.3.1）。販売開始日時 ≦ 現在時刻 < 上映開始時刻。
     * 上映開始時刻をもって販売を終了する（4.2.2-6 が開始済みの回を表示しないのと同じ境界）。
     */
    public function isOnSale(?CarbonImmutable $now = null): bool
    {
        $now ??= Date::now();

        return ! $this->isBeforeSale($now) && $this->starts_at->isAfter($now);
    }

    /**
     * キャンセルの受付期限（4.4-1）。上映開始の20分前。
     */
    public function cancelDeadline(): CarbonImmutable
    {
        return $this->starts_at->subMinutes(self::CANCEL_DEADLINE_MINUTES);
    }

    /**
     * キャンセルを受け付けられる時刻か（4.4-1）。
     *
     * **期限ちょうどは締め切る**（4.3.18 で境界を一点に固定した）。表示側（P-07 の
     * 導線の出し分け）と実行側（`ReservationService::cancel()`）の双方がここを通る。
     * 境界の向きを2箇所に複製すると、片方だけが改定されうる（`isOnSale()` と同じ理由）。
     */
    public function acceptsCancellationAt(?CarbonImmutable $now = null): bool
    {
        return ($now ?? Date::now())->isBefore($this->cancelDeadline());
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * @return BelongsTo<Theater, $this>
     */
    public function theater(): BelongsTo
    {
        return $this->belongsTo(Theater::class, 'theater_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * @return HasMany<SeatLock, $this>
     */
    public function seatLocks(): HasMany
    {
        return $this->hasMany(SeatLock::class, 'screening_id');
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'screening_id');
    }

    /**
     * @return HasMany<ReservationSeat, $this>
     */
    public function reservationSeats(): HasMany
    {
        return $this->hasMany(ReservationSeat::class, 'screening_id');
    }
}
