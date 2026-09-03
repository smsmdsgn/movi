<?php

namespace App\Livewire\Admin\Theaters;

use App\Models\Admin;
use App\Models\Cinema;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Theater;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * シアター・座席管理（A-04）。super-admin がシアター基本情報（名称・番号）を
 * 作成・編集し、座席の有効／無効切替は super-admin（全館）・cinema-admin（自館）の
 * 双方が行える（4.8.2）。座席の追加・削除・座標変更は範囲外（6.2）。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる
 * （13.4.2、A-03と同じ理由）。
 */
class Index extends Component
{
    public ?int $selectedCinemaId = null;

    public bool $showEditForm = false;

    #[Locked]
    public ?int $editingTheaterId = null;

    #[Locked]
    public ?int $editingCinemaId = null;

    public string $number = '';

    public string $name = '';

    public bool $showSeats = false;

    #[Locked]
    public ?int $seatTheaterId = null;

    #[Locked]
    public string $seatTheaterName = '';

    public function editTheater(int $theaterId): void
    {
        $admin = $this->currentAdmin();
        $theater = Theater::findOrFail($theaterId);

        Gate::forUser($admin)->authorize('update', $theater);

        $this->editingTheaterId = $theater->id;
        $this->editingCinemaId = $theater->cinema_id;
        $this->number = (string) $theater->number;
        $this->name = $theater->name;
        $this->resetErrorBag();
        $this->showEditForm = true;
    }

    public function saveTheater(): void
    {
        if ($this->editingTheaterId === null) {
            // editTheater() を経ずに呼ばれた場合（例: /livewire/updateへの不正な直接呼び出し）。
            return;
        }

        $admin = $this->currentAdmin();
        $theater = Theater::findOrFail($this->editingTheaterId);

        Gate::forUser($admin)->authorize('update', $theater);

        $data = $this->validate();

        $theater->update($data);

        $this->resetEditForm();
        Flux::toast(text: __('admin.theater.messages.saved'), variant: 'success');
    }

    public function cancelEditTheater(): void
    {
        $this->resetEditForm();
    }

    private function resetEditForm(): void
    {
        $this->reset(['editingTheaterId', 'editingCinemaId', 'number', 'name']);
        $this->resetErrorBag();
        $this->showEditForm = false;
    }

    public function manageSeats(int $theaterId): void
    {
        $admin = $this->currentAdmin();
        $theater = Theater::findOrFail($theaterId);

        Gate::forUser($admin)->authorize('toggleSeats', $theater);

        $this->seatTheaterId = $theater->id;
        $this->seatTheaterName = $theater->name;
        $this->showSeats = true;
    }

    public function closeSeats(): void
    {
        $this->reset(['seatTheaterId', 'seatTheaterName']);
        $this->showSeats = false;
    }

    /**
     * 座席の有効／無効を切り替える（4.8.2）。使用不可への切替のみ、
     * 未来の上映回に有効な予約または未期限切れの座席ロックが無いことを検証する（6.2 制約2）。
     */
    public function toggleSeat(int $seatId): void
    {
        $admin = $this->currentAdmin();
        $seat = Seat::findOrFail($seatId);

        // Seat自体はcinema_idを持たずCinemaScopeの対象外のため、親Theaterを
        // 経由して可視範囲を確認する（cinema-adminが他館の座席IDを渡した場合、
        // ここでModelNotFoundExceptionになる）。
        $theater = Theater::findOrFail($seat->theater_id);

        Gate::forUser($admin)->authorize('toggleSeats', $theater);

        if ($seat->is_available && $this->hasBlockingReservationOrLock($seat)) {
            Flux::toast(text: __('admin.theater.messages.seat_blocked'), variant: 'danger');

            return;
        }

        $seat->update(['is_available' => ! $seat->is_available]);
    }

    private function hasBlockingReservationOrLock(Seat $seat): bool
    {
        $hasActiveFutureReservation = ReservationSeat::where('seat_id', $seat->id)
            ->whereNull('released_at')
            ->whereHas('screening', fn ($query) => $query->where('starts_at', '>', Date::now()))
            ->exists();

        if ($hasActiveFutureReservation) {
            return true;
        }

        return SeatLock::where('seat_id', $seat->id)
            ->where('expires_at', '>', Date::now())
            ->exists();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'number' => [
                'required',
                'integer',
                'min:1',
                'max:999',
                Rule::unique(Theater::class, 'number')
                    ->where(fn ($query) => $query->where('cinema_id', $this->editingCinemaId))
                    ->ignore($this->editingTheaterId),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.theater.fields');

        return $attributes;
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
     * @return Collection<int, Theater>
     */
    private function visibleTheaters(): Collection
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('viewAny', Theater::class);

        $query = Theater::with('cinema')->orderBy('cinema_id')->orderBy('number');

        if ($this->canSelectCinema() && $this->selectedCinemaId !== null) {
            $query->where('cinema_id', $this->selectedCinemaId);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, Seat>
     */
    private function seatsForModal(): Collection
    {
        if (! $this->showSeats || $this->seatTheaterId === null) {
            return new Collection;
        }

        return Seat::where('theater_id', $this->seatTheaterId)
            ->with('seatType')
            ->orderBy('grid_row')
            ->orderBy('grid_col')
            ->get();
    }

    public function render(): View
    {
        return view('admin.theaters.index', [
            'theaters' => $this->visibleTheaters(),
            'seats' => $this->seatsForModal(),
            'cinemas' => $this->canSelectCinema() ? Cinema::visibleTo($this->currentAdmin())->orderBy('id')->get() : new Collection,
        ])->layout('layouts.admin', ['title' => __('admin.theater.title')]);
    }
}
