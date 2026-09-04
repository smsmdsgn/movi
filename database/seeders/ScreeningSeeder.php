<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\Theater;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DeterministicRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 上映編成（t_bookings）と上映回（t_screenings）を投入する（docs/design.md 9.1 / 9.2 / 4.8.3）。
 * 9.1は祇園ムビの上映編成・上映回を「手動」としているが、館ごとに個別の上映傾向
 * （4.1.1）を作り込むコストに見合わないため、祇園ムビも含めた全7館・45シアターに
 * 同一のアルゴリズムを適用する（6.1追記表参照）。
 * 冪等性はシアター単位で判定する（`t_bookings` に theater_id が無いため）。
 * 上映編成そのものは館単位で、同一館・同一作品・同一規格・同一期間なら
 * 兄弟シアター間で1行を共有する（`generateForTheater()`）。
 */
class ScreeningSeeder extends Seeder
{
    use DeterministicRandom;

    public function run(): void
    {
        $start = CarbonImmutable::now()->subYears(SeedConfig::GENERATION_PAST_YEARS)->startOfDay();
        $end = CarbonImmutable::now()->addWeeks(SeedConfig::GENERATION_FUTURE_WEEKS)->startOfDay();
        $allMovies = Movie::with('formats')->get();

        Theater::with('formats')
            ->get()
            ->each(function (Theater $theater) use ($allMovies, $start, $end) {
                if ($theater->screenings()->exists()) {
                    return;
                }

                $movies = $this->compatibleMoviePool($theater, $allMovies);

                if ($movies->isEmpty()) {
                    return;
                }

                DB::transaction(fn () => $this->generateForTheater($theater, $movies, $start, $end));
            });
    }

    /**
     * 対応規格が1つ以上一致する作品を、公開日の新しい順に並べて返す。
     *
     * @param  Collection<int, Movie>  $allMovies
     * @return Collection<int, Movie>
     */
    private function compatibleMoviePool(Theater $theater, Collection $allMovies): Collection
    {
        $theaterFormatIds = $theater->formats->pluck('id');

        return $allMovies
            ->filter(fn (Movie $movie) => $movie->formats->pluck('id')->intersect($theaterFormatIds)->isNotEmpty())
            ->sortByDesc('released_on')
            ->values();
    }

    /**
     * @param  Collection<int, Movie>  $movies
     */
    private function generateForTheater(Theater $theater, Collection $movies, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $theaterFormatIds = $theater->formats->pluck('id');
        $cursor = $start;
        $slot = 0;
        $screenings = [];

        while ($cursor->lt($end)) {
            $eligible = $movies->filter(fn (Movie $movie) => $movie->released_on->lte($cursor))->values();

            if ($eligible->isEmpty()) {
                $cursor = $this->nextReleaseAfter($movies, $cursor, $end);

                continue;
            }

            $movie = $this->pickMovie($theater->id, $slot, $eligible);
            $slot++;

            $format = $this->pickFormat($movie, $theaterFormatIds);
            $runEnd = $cursor->addDays(SeedConfig::BOOKING_RUN_DAYS)->min($end);

            // 同一館の兄弟シアターが同じ作品・規格・期間を選んだ場合は上映編成を
            // 1行に共有する。4.8.3 は上映編成を館単位、上映回をシアター単位と定めており
            // （`t_bookings` に theater_id が無い）、シアターごとに作ると同一の編成が
            // 館内に複数行できる。
            $booking = Booking::firstOrCreate(
                [
                    'cinema_id' => $theater->cinema_id,
                    'movie_id' => $movie->id,
                    'format_id' => $format->id,
                    'starts_on' => $cursor->toDateString(),
                    'ends_on' => $runEnd->subDay()->toDateString(),
                ],
                ['surcharge' => $format->default_surcharge]
            );

            $screenings[] = $this->screeningsForBooking($booking, $theater, $movie, $cursor, $runEnd);

            $cursor = $runEnd;
        }

        collect($screenings)->flatten(1)->chunk(500)->each(fn ($chunk) => Screening::query()->insert($chunk->all()));
    }

    /**
     * 未公開の作品しか無い期間をスキップし、次にいずれかの作品が公開される日まで進める。
     * 生成範囲内に公開日を迎える作品が無ければ生成終了日まで一気に進めてループを終わらせる。
     *
     * @param  Collection<int, Movie>  $movies
     */
    private function nextReleaseAfter(Collection $movies, CarbonImmutable $cursor, CarbonImmutable $end): CarbonImmutable
    {
        $nextRelease = $movies
            ->map(fn (Movie $movie) => $movie->released_on)
            ->filter(fn (CarbonImmutable $releasedOn) => $releasedOn->gt($cursor))
            ->sort()
            ->first();

        return $nextRelease?->startOfDay() ?? $end;
    }

    /**
     * シアターと上映順（slot）から決定的に1本選ぶ。シアターごとに異なる系列になるようIDを種にする。
     * 直近公開の上位から選ぶ確率を`SeedConfig::RECENT_MOVIE_BIAS_PERCENT`とし、
     * 「公開年の新しい作品を優先する」（9.1）を近似する。
     *
     * @param  Collection<int, Movie>  $eligibleMoviesDescByReleaseDate  公開日の新しい順
     */
    private function pickMovie(int $theaterId, int $slot, Collection $eligibleMoviesDescByReleaseDate): Movie
    {
        $recentCount = max(1, intdiv($eligibleMoviesDescByReleaseDate->count(), SeedConfig::RECENT_MOVIE_POOL_DIVISOR));
        $preferRecent = $this->deterministicRatio("{$theaterId}-bias-{$slot}") < SeedConfig::RECENT_MOVIE_BIAS_PERCENT;
        $pool = ($preferRecent ? $eligibleMoviesDescByReleaseDate->take($recentCount) : $eligibleMoviesDescByReleaseDate)->values();
        $index = $this->deterministicIndex("{$theaterId}-{$slot}", $pool->count());

        return $pool[$index];
    }

    /**
     * 作品とシアターの両方が対応する規格のうち、追加料金が最も高いもの（プレミアム規格）を選ぶ。
     * `compatibleMoviePool()` が両者の規格の積集合が空でない作品のみを渡すため、必ず1件以上ある。
     *
     * @param  Collection<int, int>  $theaterFormatIds
     */
    private function pickFormat(Movie $movie, Collection $theaterFormatIds): Format
    {
        return $movie->formats
            ->whereIn('id', $theaterFormatIds->all())
            ->sortByDesc('default_surcharge')
            ->first();
    }

    /**
     * 1つの上映編成の期間中、営業時間内（10:00〜23:55）で
     * 上映時間+予告編15分+入替30分の間隔を空け、23:55を超えない範囲まで上映回を並べる（4.8.3）。
     * `$runsUntil` は排他的な終端（次の上映編成の開始日と同じ値）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function screeningsForBooking(Booking $booking, Theater $theater, Movie $movie, CarbonImmutable $runsFrom, CarbonImmutable $runsUntil): array
    {
        $screeningMinutes = $movie->runtime_minutes + SeedConfig::TRAILER_MINUTES;
        $now = now();
        $screenings = [];

        for ($day = $runsFrom; $day->lt($runsUntil); $day = $day->addDay()) {
            $slotStart = $this->combine($day, SeedConfig::DAILY_FIRST_SCREENING_TIME);
            $closing = $this->combine($day, SeedConfig::DAILY_LAST_SCREENING_END_TIME);

            while (($endsAt = $slotStart->addMinutes($screeningMinutes))->lte($closing)) {
                $screenings[] = [
                    'booking_id' => $booking->id,
                    'theater_id' => $theater->id,
                    'created_by_admin_id' => null,
                    'starts_at' => $slotStart,
                    'ends_at' => $endsAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $slotStart = $endsAt->addMinutes(SeedConfig::SCREENING_INTERVAL_MINUTES);
            }
        }

        return $screenings;
    }

    private function combine(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = explode(':', $time);

        return $day->setTime((int) $hour, (int) $minute);
    }
}
