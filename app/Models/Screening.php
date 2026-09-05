<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
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
