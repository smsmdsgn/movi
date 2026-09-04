<?php

namespace App\Models;

use App\Models\Scopes\CinemaScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $cinema_id
 * @property int $movie_id
 * @property int $format_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $surcharge
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['cinema_id', 'movie_id', 'format_id', 'starts_on', 'ends_on', 'surcharge'])]
class Booking extends Model
{
    protected $table = 't_bookings';

    protected static function booted(): void
    {
        static::addGlobalScope(new CinemaScope);
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'surcharge' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cinema, $this>
     */
    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class, 'cinema_id');
    }

    /**
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'movie_id');
    }

    /**
     * @return BelongsTo<Format, $this>
     */
    public function format(): BelongsTo
    {
        return $this->belongsTo(Format::class, 'format_id');
    }

    /**
     * @return HasMany<Screening, $this>
     */
    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class, 'booking_id');
    }
}
