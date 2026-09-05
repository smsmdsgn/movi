<?php

namespace App\Livewire\Admin\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Admin;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\Theater;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 予約状況（A-10）。上映回ごとの予約状況を確認する（4.8.2 / 4.8.5）。
 * `super-admin` は館セレクタで全館を横断し、`cinema-admin` は自館固定（4.8.5）。
 *
 * **表示範囲は 予約者名 / 座席 / 券種 / 入場状態 と識別用の予約番号に限る。**
 * メールアドレス・決済情報・金額は表示しない（4.8.5）。
 *
 * 館の絞り込みは `CinemaScope` 適用済みの `Booking` を `screening.booking` 経由で
 * たどって担保する。`Reservation` 自体にグローバルスコープは適用しない
 * （4.8.6追記表「A-10の館スコープ」）。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる。
 * `AuthorizesRequests::authorize()` は既定ガード（`web`）のユーザーを解決するため、
 * `admin` ガードでは常に未許可と判定される（13.4.2、AppServiceProviderと同じ理由）。
 */
class Index extends Component
{
    use WithPagination;

    public ?int $selectedCinemaId = null;

    public string $filterDate = '';

    public ?int $filterTheaterId = null;

    // `<flux:modal wire:model.self="showDetail">` がクライアント側（ESC・背景クリック）
    // からの二方向バインディングでこの値を更新するため、Lockedにしない。
    public bool $showDetail = false;

    #[Locked]
    public ?int $detailScreeningId = null;

    public function mount(): void
    {
        $this->filterDate = CarbonImmutable::now()->toDateString();
    }

    public function updatedSelectedCinemaId(): void
    {
        $this->filterTheaterId = null;
        $this->resetPage();
    }

    public function updatedFilterDate(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTheaterId(): void
    {
        $this->resetPage();
    }

    /**
     * 上映回の予約明細を開く。一覧と同じ可視範囲の制約を通す。
     */
    public function showReservations(int $screeningId): void
    {
        Gate::forUser($this->currentAdmin())->authorize('viewAny', Reservation::class);

        // 可視範囲の確認はここで完結させず、`detailReservations()` が毎回
        // `visibleScreeningQuery()` を経由して取得する（所属館の変更に追随するため）。
        $this->visibleScreeningQuery()->findOrFail($screeningId);

        $this->detailScreeningId = $screeningId;
        $this->showDetail = true;
    }

    public function closeDetail(): void
    {
        $this->detailScreeningId = null;
        $this->showDetail = false;
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
     * 絞り込みの対象となる館。`cinema-admin` は自館固定（4.8.5）。
     * `super-admin` が未選択の場合は `null`（全館）。
     */
    private function targetCinemaId(): ?int
    {
        return $this->canSelectCinema()
            ? $this->selectedCinemaId
            : $this->currentAdmin()->cinema_id;
    }

    private function selectedDate(): CarbonImmutable
    {
        $parsed = CarbonImmutable::hasFormat($this->filterDate, 'Y-m-d')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->filterDate)
            : null;

        return $parsed instanceof CarbonImmutable ? $parsed : CarbonImmutable::now();
    }

    /**
     * 可視範囲の上映回。親 `Booking` の `CinemaScope` を継承させ、他館を除外する。
     *
     * @return Builder<Screening>
     */
    private function visibleScreeningQuery(): Builder
    {
        $cinemaId = $this->targetCinemaId();

        return Screening::query()
            ->whereHas('booking', fn ($query) => $query->when(
                $cinemaId !== null,
                fn ($booking) => $booking->where('cinema_id', $cinemaId)
            ));
    }

    /**
     * 当日の上映回と、その予約状況の集計。
     *
     * 集計対象は `paid` のみとする。`pending` は 6.4.2 により
     * `t_reservation_seats` を持たず座席を表示できない。`expired` は座席を
     * 解放済みで確認の対象にならない（4.8.6追記表「A-10で扱う予約のステータス」）。
     *
     * @return LengthAwarePaginator<int, Screening>
     */
    private function visibleScreenings(): LengthAwarePaginator
    {
        Gate::forUser($this->currentAdmin())->authorize('viewAny', Reservation::class);

        return $this->visibleScreeningQuery()
            ->with([
                'booking.cinema',
                'booking.movie',
                'theater' => fn ($query) => $query->withCount([
                    'seats as available_seats_count' => fn ($seats) => $seats->available(),
                ]),
            ])
            ->withCount([
                'reservations as paid_reservations_count' => fn ($query) => $query
                    ->where('status', ReservationStatus::Paid),
                'reservations as checked_in_count' => fn ($query) => $query
                    ->where('status', ReservationStatus::Paid)
                    ->whereNotNull('checked_in_at'),
                'reservationSeats as booked_seats_count' => fn ($query) => $query
                    ->whereNull('released_at'),
            ])
            ->whereDate('starts_at', $this->selectedDate()->toDateString())
            ->when($this->filterTheaterId !== null, fn ($query) => $query->where('theater_id', $this->filterTheaterId))
            ->orderBy('starts_at')
            ->orderBy('theater_id')
            ->paginate(20);
    }

    /**
     * 明細に表示する予約。`paid` と `cancelled` のみを対象とする。
     *
     * 上映回の可視範囲は毎回 `visibleScreeningQuery()` から導出する。
     * `showReservations()` の一度きりの確認に依存すると、モーダルを開いたままの
     * `cinema-admin` の所属館が A-14 で変更された場合に、変更前の館の明細を
     * 表示し続ける（17.2.1）。
     *
     * 座席は `released_at` で絞らない。4.4-2 によりキャンセルは予約単位であり、
     * 6.4.2 により `released_at IS NULL` は `status = paid` と同値になるため、
     * 絞ると `cancelled` の行だけ座席・券種が空欄になる。占有席数の集計
     * （`booked_seats_count`）とは目的が異なる。
     *
     * @return Collection<int, Reservation>
     */
    private function detailReservations(): Collection
    {
        if (! $this->showDetail || $this->detailScreeningId === null) {
            return new Collection;
        }

        Gate::forUser($this->currentAdmin())->authorize('viewAny', Reservation::class);

        return Reservation::query()
            ->whereIn(
                'screening_id',
                $this->visibleScreeningQuery()->whereKey($this->detailScreeningId)->select('id')
            )
            ->whereIn('status', [ReservationStatus::Paid, ReservationStatus::Cancelled])
            // 4.8.5: メールアドレス・決済情報を扱わないため、必要な列のみ取得する。
            ->select([
                'id', 'reservation_no', 'user_id', 'guest_name',
                'contact_type', 'screening_id', 'status', 'checked_in_at',
            ])
            ->with([
                'user:id,name',
                'seats' => fn ($query) => $query
                    ->select(['id', 'reservation_id', 'seat_id', 'ticket_type_id'])
                    ->with(['seat:id,row_label,seat_number', 'ticketType:id,name']),
            ])
            ->orderBy('reservation_no')
            ->get();
    }

    /**
     * 一覧の絞り込み用のシアター。館が定まらない間は選択肢を出さない
     * （全館では7館45シアターが並ぶため。A-09 と同じ）。
     *
     * @return Collection<int, Theater>
     */
    private function filterTheaters(): Collection
    {
        $cinemaId = $this->targetCinemaId();

        if ($cinemaId === null) {
            return new Collection;
        }

        return Theater::where('cinema_id', $cinemaId)->orderBy('number')->get();
    }

    public function render(): View
    {
        return view('admin.reservations.index', [
            'screenings' => $this->visibleScreenings(),
            'cinemas' => $this->canSelectCinema()
                ? Cinema::visibleTo($this->currentAdmin())->orderBy('id')->get()
                : new Collection,
            'theaters' => $this->filterTheaters(),
            'canSelectCinema' => $this->canSelectCinema(),
            'reservations' => $this->detailReservations(),
        ])->layout('layouts.admin', ['title' => __('admin.reservation.title')]);
    }
}
