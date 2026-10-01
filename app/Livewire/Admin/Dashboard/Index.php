<?php

namespace App\Livewire\Admin\Dashboard;

use App\Enums\ReservationStatus;
use App\Models\Admin;
use App\Models\Cinema;
use App\Models\Post;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * ダッシュボード（A-02）。7.16 の6項目を集計する。
 * `super-admin` は館セレクタで全館を横断し、`cinema-admin` は自館固定（4.8.5）。
 *
 * 予約の数え方・館の範囲は A-10（予約状況）・A-12（お知らせ）の一覧と揃える
 * （4.8.6追記表「A-02（ダッシュボード）の実装」ほか）。ダッシュボードの数字と、
 * そこから開いた各画面の数字を食い違わせないため。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる
 * （13.4.2、A-10 と同じ理由）。**表示には `view-admin-screen`（ルート）に加え、
 * `ReservationPolicy::viewAny` と `PostPolicy::viewAny` の双方を要する。** いずれかを
 * 改めると画面全体が403になりうるため、到達可否を変える場合は3つを揃えること。
 */
class Index extends Component
{
    use WithPagination;

    /** 予約件数の推移を示す日数。本日を含む（7.16-4「直近1週間」）。 */
    public const int TREND_DAYS = 7;

    public ?int $selectedCinemaId = null;

    public function updatedSelectedCinemaId(): void
    {
        $this->resetPage();
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    private function canSelectCinema(): bool
    {
        return Gate::forUser($this->currentAdmin())->allows('viewAllCinemas', Cinema::class);
    }

    /**
     * 集計の対象となる館。`cinema-admin` は自館固定（4.8.5）。
     * `super-admin` が未選択の場合は `null`（全館）。
     *
     * お知らせ（`Post`）は `CinemaScope` を通らず、所属館が未設定の管理者を素通しすると
     * `forCinema(null)` が全館の記事を数える。A-12 と同じく403として即座に検出する。
     */
    private function targetCinemaId(): ?int
    {
        if ($this->canSelectCinema()) {
            return $this->selectedCinemaId;
        }

        $cinemaId = $this->currentAdmin()->cinema_id;

        abort_if($cinemaId === null, 403, '所属館が設定されていない管理者です。');

        return $cinemaId;
    }

    /**
     * 可視範囲の上映回のうち、開始日時が `[$from, $until)` に入るもの。
     * 親 `Booking` の `CinemaScope` を継承させ、他館を除外する（A-10 と同じ）。
     *
     * @return Builder<Screening>
     */
    private function screeningsStartingBetween(CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        $cinemaId = $this->targetCinemaId();

        return Screening::query()
            ->whereHas('booking', fn ($query) => $query->when(
                $cinemaId !== null,
                fn ($booking) => $booking->where('cinema_id', $cinemaId)
            ))
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $until);
    }

    /**
     * @return Builder<Screening>
     */
    private function todayScreenings(CarbonImmutable $today): Builder
    {
        return $this->screeningsStartingBetween($today, $today->addDay());
    }

    /**
     * 7.16-1〜3。予約は `paid` のみ、座席は占有中のもののみを数える（A-10 と同じ）。
     *
     * @return array{screenings: int, reservations: int, seats: int, checkedIn: int, checkInRate: int|null}
     */
    private function todaySummary(CarbonImmutable $today): array
    {
        $screeningIds = $this->todayScreenings($today)->select('id');

        $paidReservations = fn (): Builder => Reservation::query()
            ->where('status', ReservationStatus::Paid)
            ->whereIn('screening_id', $screeningIds);

        $reservations = $paidReservations()->count();
        $checkedIn = $paidReservations()->whereNotNull('checked_in_at')->count();

        return [
            'screenings' => $this->todayScreenings($today)->count(),
            'reservations' => $reservations,
            'seats' => ReservationSeat::query()->occupying()->whereIn('screening_id', $screeningIds)->count(),
            'checkedIn' => $checkedIn,
            // 予約が0件のときに「0%」と出すと、入場が振るわないように見える。率そのものを出さない。
            'checkInRate' => $reservations > 0 ? intdiv($checkedIn * 100, $reservations) : null,
        ];
    }

    /**
     * 7.16-4。上映日ごとの予約件数（`paid`）。予約の無い日も0件として返す。
     * 購入日ではなく上映日で数える（4.8.6追記表「A-02の予約件数の推移」）。
     *
     * @return list<array{date: CarbonImmutable, count: int}>
     */
    private function reservationTrend(CarbonImmutable $today): array
    {
        $from = $today->subDays(self::TREND_DAYS - 1);

        /** @var SupportCollection<string, int> $counts */
        $counts = Reservation::query()
            ->join('t_screenings', 't_screenings.id', '=', 't_reservations.screening_id')
            ->where('t_reservations.status', ReservationStatus::Paid)
            ->whereIn('t_reservations.screening_id', $this->screeningsStartingBetween($from, $today->addDay())->select('id'))
            ->selectRaw('DATE(t_screenings.starts_at) AS screening_date, COUNT(*) AS reservations_count')
            ->groupBy('screening_date')
            ->toBase()
            ->pluck('reservations_count', 'screening_date');

        $trend = [];

        for ($date = $from; $date->lte($today); $date = $date->addDay()) {
            $trend[] = [
                'date' => $date,
                'count' => (int) ($counts[$date->toDateString()] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * 7.16-5。A-10 の一覧と同じ集計を、開始時刻順に出す。
     *
     * @return LengthAwarePaginator<int, Screening>
     */
    private function todayScreeningList(CarbonImmutable $today): LengthAwarePaginator
    {
        return $this->todayScreenings($today)
            ->with([
                'booking.cinema',
                'booking.movie',
                'theater' => fn ($query) => $query->withCount([
                    'seats as available_seats_count' => fn ($seats) => $seats->available(),
                ]),
            ])
            ->withCount([
                'reservationSeats as booked_seats_count' => fn ($query) => $query->occupying(),
            ])
            ->orderBy('starts_at')
            ->orderBy('theater_id')
            ->paginate(20);
    }

    /**
     * 7.16-6。A-12 の一覧と同じ範囲（`Post::forCinema()`）で数える。
     */
    private function unpublishedPostCount(): int
    {
        Gate::forUser($this->currentAdmin())->authorize('viewAny', Post::class);

        return Post::query()
            ->forCinema($this->targetCinemaId())
            ->unpublished()
            ->count();
    }

    public function render(): View
    {
        // `/livewire/update` 経由の直接呼び出しに備える（A-10 と同じ理由）。
        Gate::forUser($this->currentAdmin())->authorize('viewAny', Reservation::class);

        // 「本日」は1回の描画で1度だけ決める。0時をまたいで描画しても、集計・推移・一覧の
        // 「本日」を食い違わせない。
        $today = CarbonImmutable::today();

        return view('admin.dashboard.index', [
            'summary' => $this->todaySummary($today),
            'trend' => $this->reservationTrend($today),
            'screenings' => $this->todayScreeningList($today),
            'unpublishedPostCount' => $this->unpublishedPostCount(),
            'cinemas' => $this->canSelectCinema()
                ? Cinema::visibleTo($this->currentAdmin())->orderBy('id')->get()
                : new Collection,
            'canSelectCinema' => $this->canSelectCinema(),
        ])->layout('layouts.admin', ['title' => __('admin.dashboard.title')]);
    }
}
