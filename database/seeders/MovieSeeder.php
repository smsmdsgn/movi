<?php

namespace Database\Seeders;

use App\Models\Format;
use App\Models\Movie;
use Illuminate\Database\Seeder;

/**
 * 上映作品プールを投入する（docs/design.md 9.1）。祇園ムビが手動選定した作品として扱い、
 * 他館は上映編成（t_bookings）投入時にこのプールから流用する。
 */
class MovieSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SeedConfig::MOVIES as $movieConfig) {
            $movie = Movie::firstOrCreate(
                ['tmdb_id' => $movieConfig['tmdb_id']],
                [
                    'title' => $movieConfig['title'],
                    'synopsis' => $movieConfig['synopsis'],
                    'runtime_minutes' => $movieConfig['runtime_minutes'],
                    'released_on' => $movieConfig['released_on'],
                    'genres' => $movieConfig['genres'],
                ]
            );

            $formatIds = Format::whereIn('name', ['2D', ...$movieConfig['formats']])->pluck('id');
            $movie->formats()->syncWithoutDetaching($formatIds);
        }
    }
}
