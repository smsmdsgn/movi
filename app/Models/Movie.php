<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tmdb_id
 * @property string $title
 * @property string|null $original_title
 * @property string $synopsis
 * @property string|null $poster_path
 * @property int $runtime_minutes
 * @property CarbonImmutable $released_on
 * @property array<int, string>|null $genres
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['tmdb_id', 'title', 'original_title', 'synopsis', 'poster_path', 'runtime_minutes', 'released_on', 'genres'])]
class Movie extends Model
{
    protected $table = 'm_movies';

    protected function casts(): array
    {
        return [
            'tmdb_id' => 'integer',
            'runtime_minutes' => 'integer',
            'released_on' => 'date',
            'genres' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<Format, $this>
     */
    public function formats(): BelongsToMany
    {
        return $this->belongsToMany(Format::class, 'm_movie_format', 'movie_id', 'format_id')->withTimestamps();
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'movie_id');
    }
}
