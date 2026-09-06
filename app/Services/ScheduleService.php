<?php

namespace App\Services;

use App\Enums\MovieListingCategory;
use App\Enums\SeatAvailability;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * 上映情報閲覧（F-02、4.2）の表示データを組み立てる。
 *
 * - 上映スケジュール表（7.4）: 日付ごとの上映回を作品ブロックにまとめ、空席状況を付ける
 * - 作品一覧タブ（7.3）: 選択中の館の上映編成（`t_bookings`）から作品を 上映中 / 公開予定 / 上映終了 に区分する
 *
 * 館は呼び出し側（コントローラ・Livewire コンポーネント）が引数で渡す（13.4.1 / 4.1.3追記表）。
 * 顧客側のルートでは `CinemaScope` が適用されない（`SkipCinemaScope`）ため、
 * 館の絞り込みは本サービスが `t_bookings.cinema_id` で明示的に行う。
 */
class ScheduleService
{
    /** 日付タブで切り替えられる日数（4.2.2-1）。当日を含む。 */
    public const int DISPLAY_DAYS = 7;

    /** 販売開始は上映日の何日前の 0:00 か（4.2.2-5 / 4.3.1）。 */
    public const int SALES_START_DAYS_BEFORE = 3;

    /**
     * 日付タブに並べる日付（当日から `DISPLAY_DAYS` 日分）。
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function displayDates(?CarbonImmutable $today = null): Collection
    {
        $today = ($today ?? Date::now())->startOfDay();

        return collect(range(0, self::DISPLAY_DAYS - 1))
            ->map(fn (int $offset): CarbonImmutable => $today->addDays($offset));
    }

    /**
     * 指定日の上映スケジュール表（7.4）。`$movie` を渡すと当該作品の回に絞る（7.5-9）。
     *
     * 開始済みの回は表示しない（4.2.2-6）。作品ブロックは当日の最初の回が早い順、
     * 同時刻なら作品名順に並べる。
     *
     * @return Collection<int, ScheduleBlock>
     */
    public function blocksOn(Cinema $cinema, CarbonImmutable $date, ?Movie $movie = null, ?CarbonImmutable $now = null): Collection
    {
        $now ??= Date::now();

        $screenings = Screening::query()
            ->with(['booking.movie', 'booking.format', 'theater'])
            ->whereHas('booking', function ($query) use ($cinema, $movie): void {
                $query->where('cinema_id', $cinema->id);

                if ($movie !== null) {
                    $query->where('movie_id', $movie->id);
                }
            })
            ->whereBetween('starts_at', [$date->startOfDay(), $date->endOfDay()])
            ->where('starts_at', '>', $now)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $slots = $this->buildSlots($screenings, $now);

        return $slots
            ->groupBy(fn (ScheduleSlot $slot): int => $slot->screening->booking->movie_id)
            ->map(function (Collection $movieSlots): ScheduleBlock {
                /** @var ScheduleSlot $first */
                $first = $movieSlots->first();

                $formats = $movieSlots
                    ->map(fn (ScheduleSlot $slot): Format => $slot->screening->booking->format)
                    ->unique(fn (Format $format): int => $format->id)
                    ->sortBy(fn (Format $format): int => $format->id)
                    ->values();

                return new ScheduleBlock($first->screening->booking->movie, $formats, $movieSlots->values());
            })
            ->sortBy(fn (ScheduleBlock $block): array => [
                $block->slots->first()?->screening->starts_at->getTimestamp() ?? 0,
                $block->movie->title,
            ])
            ->values();
    }

    /**
     * 作品一覧タブ（7.3）の区分。選択中の館の上映編成を当日日付で判定する（4.2.1）。
     *
     * 1作品に複数の編成（規格違い・再上映）がある場合は 上映中 > 公開予定 > 上映終了 の
     * 優先順で1つの区分にのみ入れる（`MovieListing::fromBookings()`）。並び順は
     * 上映中: 開始日の新しい順、公開予定: 開始日の早い順、上映終了: 終了日の新しい順
     * （いずれも区分に該当する編成の日付で判定する）。
     *
     * @return array<string, Collection<int, MovieListing>> `MovieListingCategory` の値をキーとする
     */
    public function movieListings(Cinema $cinema, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? Date::now())->startOfDay();

        $bookings = Booking::query()
            ->with(['movie', 'format'])
            ->where('cinema_id', $cinema->id)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $listings = $bookings
            ->groupBy('movie_id')
            ->map(fn (EloquentCollection $movieBookings): MovieListing => MovieListing::fromBookings($movieBookings, $today));

        $byCategory = fn (MovieListingCategory $category): Collection => $listings
            ->filter(fn (MovieListing $listing): bool => $listing->category === $category);

        return [
            MovieListingCategory::Now->value => $byCategory(MovieListingCategory::Now)
                ->sortByDesc(fn (MovieListing $listing): string => $listing->startsOn->toDateString())
                ->values(),
            MovieListingCategory::Upcoming->value => $byCategory(MovieListingCategory::Upcoming)
                ->sortBy(fn (MovieListing $listing): string => $listing->startsOn->toDateString())
                ->values(),
            MovieListingCategory::Ended->value => $byCategory(MovieListingCategory::Ended)
                ->sortByDesc(fn (MovieListing $listing): string => $listing->endsOn->toDateString())
                ->values(),
        ];
    }

    /**
     * 作品詳細（7.5-7〜8）用に、選択中の館での当該作品の上映編成を開始日順で返す。
     * 1件も無い場合、当該作品はこの館のページとしては存在しない扱いとする（4.1.3追記表）。
     *
     * @return EloquentCollection<int, Booking>
     */
    public function bookingsOf(Cinema $cinema, Movie $movie): EloquentCollection
    {
        return Booking::query()
            ->with('format')
            ->where('cinema_id', $cinema->id)
            ->where('movie_id', $movie->id)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * 上映回ごとの空席状況を一括集計する（5.3-1。上映回ごとにクエリを発行しない）。
     *
     * 座席総数は `m_seats.is_available = true` のみを数え、確定済みの予約座席
     * （`ReservationSeat::occupying()`）と有効な座席ロック（`SeatLock::active()`）を
     * 減算する（7.4）。予約座席とロックは `UNION` で座席単位に重複を除いてから数える。
     * 占有側は `is_available` を問わない（使用不可の座席に有効な予約・ロックが残る状態は
     * 6.2 制約2により未来の上映回では生じない）。
     *
     * @param  EloquentCollection<int, Screening>  $screenings
     * @return Collection<int, ScheduleSlot>
     */
    private function buildSlots(EloquentCollection $screenings, CarbonImmutable $now): Collection
    {
        if ($screenings->isEmpty()) {
            return collect();
        }

        $screeningIds = $screenings->modelKeys();
        $theaterIds = $screenings->pluck('theater_id')->unique()->values()->all();

        /** @var Collection<int, int> $totalSeatsByTheater */
        $totalSeatsByTheater = Seat::query()
            ->available()
            ->whereIn('theater_id', $theaterIds)
            ->select('theater_id', DB::raw('COUNT(*) AS seat_count'))
            ->groupBy('theater_id')
            ->pluck('seat_count', 'theater_id')
            ->map(fn (int|string $count): int => (int) $count);

        $occupiedSeats = ReservationSeat::query()
            ->occupying()
            ->select(['screening_id', 'seat_id'])
            ->whereIn('screening_id', $screeningIds)
            ->union(
                SeatLock::query()
                    ->active($now)
                    ->select(['screening_id', 'seat_id'])
                    ->whereIn('screening_id', $screeningIds)
            );

        /** @var Collection<int, int> $occupiedByScreening */
        $occupiedByScreening = DB::query()
            ->fromSub($occupiedSeats, 'occupied')
            ->select('screening_id', DB::raw('COUNT(*) AS occupied_count'))
            ->groupBy('screening_id')
            ->pluck('occupied_count', 'screening_id')
            ->map(fn (int|string $count): int => (int) $count);

        return $screenings->map(function (Screening $screening) use ($totalSeatsByTheater, $occupiedByScreening, $now): ScheduleSlot {
            $total = $totalSeatsByTheater->get($screening->theater_id, 0);
            $remaining = max(0, $total - $occupiedByScreening->get($screening->id, 0));

            $availability = $this->isBeforeSale($screening, $now)
                ? SeatAvailability::BeforeSale
                : SeatAvailability::fromSeatCounts($total, $remaining);

            return new ScheduleSlot($screening, $availability);
        })->values();
    }

    /**
     * 販売開始（上映日の3日前 0:00、4.2.2-5）に達していないか。
     */
    public function isBeforeSale(Screening $screening, ?CarbonImmutable $now = null): bool
    {
        $now ??= Date::now();

        return $screening->starts_at->startOfDay()->subDays(self::SALES_START_DAYS_BEFORE)->isAfter($now);
    }
}
