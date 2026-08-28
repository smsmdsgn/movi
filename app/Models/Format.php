<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $default_surcharge
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'default_surcharge'])]
class Format extends Model
{
    protected $table = 'm_formats';

    protected function casts(): array
    {
        return [
            'default_surcharge' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Theater, $this>
     */
    public function theaters(): BelongsToMany
    {
        return $this->belongsToMany(Theater::class, 'm_theater_format', 'format_id', 'theater_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Movie, $this>
     */
    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'm_movie_format', 'format_id', 'movie_id')->withTimestamps();
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'format_id');
    }
}
