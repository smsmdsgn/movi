<?php

namespace App\Models;

use App\Services\TmdbService;
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

    /**
     * シーダー（9.1）が投入する架空作品の `tmdb_id` の下限。実在のTMDB IDと
     * 衝突しないダミー値であり、この値以上の作品はTMDB上に対応するページを持たない。
     * `database/seeders/SeedConfig.php` の `MOVIES` はこの前提で採番する。
     */
    public const int TMDB_ID_DUMMY_MIN = 900000000;

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
     * ポスター画像のURL（7.5-1）。`poster_path` はTMDBが返す相対パスであり、
     * 配信元のホストは `TmdbService` が付与する（8.1 / 17.7 のCSP `img-src`）。
     * 初期データ（9.1）は `poster_path` を持たないため `null` を返す。
     */
    public function posterUrl(string $size = TmdbService::POSTER_SIZE_THUMBNAIL): ?string
    {
        return TmdbService::posterUrl($this->poster_path, $size);
    }

    /**
     * TMDB上に対応する作品ページが存在するか。シーダーが投入する架空作品
     * （9.1、ダミーの `tmdb_id`）では `false` となり、リンクを表示しない。
     */
    public function hasTmdbPage(): bool
    {
        return $this->tmdb_id < self::TMDB_ID_DUMMY_MIN;
    }

    /**
     * TMDB の作品ページのURL（7.5-10）。`hasTmdbPage()` が `true` の場合のみ有効。
     */
    public function tmdbUrl(): string
    {
        return 'https://www.themoviedb.org/movie/'.$this->tmdb_id;
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
