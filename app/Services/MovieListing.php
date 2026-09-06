<?php

namespace App\Services;

use App\Enums\MovieListingCategory;
use App\Models\Booking;
use App\Models\Format;
use App\Models\Movie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * 作品一覧タブ（7.3）の1作品分。選択中の館の上映編成（`t_bookings`）を作品単位にまとめ、
 * 当日日付で 上映中 / 公開予定 / 上映終了 に区分する（4.2.1）。
 *
 * 1作品に複数の編成（規格違い・再上映）がある場合、区分は 上映中 > 公開予定 > 上映終了 の
 * 優先順で1つに決め、表示する期間・規格は**その区分に該当する編成のみ**から求める。
 * 全編成の最小開始日〜最大終了日に丸めると、離れた再上映があるときに実在しない
 * 連続期間になるため（4.2.3追記表）。
 */
final readonly class MovieListing
{
    /**
     * @param  Collection<int, Format>  $formats  区分に該当する編成の上映規格（重複なし）
     * @param  CarbonImmutable  $startsOn  区分に該当する編成のうち最も早い上映開始日
     * @param  CarbonImmutable  $endsOn  区分に該当する編成のうち最も遅い上映終了日
     */
    private function __construct(
        public Movie $movie,
        public MovieListingCategory $category,
        public Collection $formats,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
    ) {}

    /**
     * @param  EloquentCollection<int, Booking>  $bookings  同一作品の上映編成（`movie` / `format` 先読み済み）
     */
    public static function fromBookings(EloquentCollection $bookings, CarbonImmutable $today): self
    {
        $nowShowing = $bookings->filter(
            fn (Booking $booking): bool => $booking->starts_on->lte($today) && $booking->ends_on->gte($today)
        );
        $upcoming = $bookings->filter(fn (Booking $booking): bool => $booking->starts_on->gt($today));

        [$category, $relevant] = match (true) {
            $nowShowing->isNotEmpty() => [MovieListingCategory::Now, $nowShowing],
            $upcoming->isNotEmpty() => [MovieListingCategory::Upcoming, $upcoming],
            default => [MovieListingCategory::Ended, $bookings],
        };

        /** @var Booking $first */
        $first = $relevant->first();
        /** @var CarbonImmutable $startsOn */
        $startsOn = $relevant->min('starts_on');
        /** @var CarbonImmutable $endsOn */
        $endsOn = $relevant->max('ends_on');

        return new self(
            movie: $first->movie,
            category: $category,
            formats: $relevant
                ->map(fn (Booking $booking): Format => $booking->format)
                ->unique(fn (Format $format): int => $format->id)
                ->sortBy(fn (Format $format): int => $format->id)
                ->values(),
            startsOn: $startsOn,
            endsOn: $endsOn,
        );
    }
}
