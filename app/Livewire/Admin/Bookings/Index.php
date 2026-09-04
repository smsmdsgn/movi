<?php

namespace App\Livewire\Admin\Bookings;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Theater;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 上映編成（A-08）。super-admin が「どの作品をどの館でいつまで上映するか」を
 * 登録・編集する（4.8.2 / 4.8.3）。cinema-admin は閲覧のみで、上映回（A-09）が
 * 現場の権限となる。`t_bookings` は `cinema_id` を持つため `Booking` に
 * `CinemaScope`（13.4.1）を適用し、館セレクタは全館横断が許される役割にのみ出す。
 *
 * 削除は実装しない（4.8.2が作成・編集のみを定め、6.2が過去データを削除しないため）。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる
 * （13.4.2、A-03〜A-07と同じ理由）。
 */
class Index extends Component
{
    use WithPagination;

    public ?int $selectedCinemaId = null;

    /**
     * `wire:model.self` でモーダルと二方向バインドするため `#[Locked]` にできない
     * （A-03〜A-07と同じ）。更新対象は `booking_id`（`#[Locked]`）が特定する。
     */
    public bool $showForm = false;

    #[Locked]
    public ?int $booking_id = null;

    /** 編集対象に上映回がぶら下がっているか。Bladeが入力欄の可否を切り替えるための表示専用の値。 */
    #[Locked]
    public bool $hasScreenings = false;

    public string $cinema_id = '';

    public string $movie_id = '';

    public string $format_id = '';

    public string $starts_on = '';

    public string $ends_on = '';

    public string $surcharge = '';

    public function createBooking(): void
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('create', Booking::class);

        $this->resetForm();
        $this->cinema_id = (string) ($this->selectedCinemaId ?? $admin->cinema_id ?? '');
        $this->showForm = true;
    }

    public function editBooking(int $bookingId): void
    {
        $admin = $this->currentAdmin();
        $booking = Booking::findOrFail($bookingId);

        Gate::forUser($admin)->authorize('update', $booking);

        $this->booking_id = $booking->id;
        $this->hasScreenings = $booking->screenings()->exists();
        $this->cinema_id = (string) $booking->cinema_id;
        $this->movie_id = (string) $booking->movie_id;
        $this->format_id = (string) $booking->format_id;
        $this->starts_on = $booking->starts_on->toDateString();
        $this->ends_on = $booking->ends_on->toDateString();
        $this->surcharge = (string) $booking->surcharge;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    /**
     * 規格を選び直したとき、追加料金へ既定額を流し込む（6.5.3-2。手動上書き可）。
     * Livewireの`updated`フックは画面からの更新でのみ発火するため、
     * `editBooking()` が読み込んだ既存の `surcharge` を壊さない。
     */
    public function updatedFormatId(string $value): void
    {
        $format = $value === '' ? null : Format::find((int) $value);

        if ($format !== null) {
            $this->surcharge = (string) $format->default_surcharge;
        }
    }

    /**
     * 館または作品を選び直すと、選べる規格（6.6の積集合）が変わる。選択済みの規格が
     * 新しい積集合から外れたまま残ると、選択肢に無い値のまま保存を試みることになるため
     * 規格と追加料金を落とす。
     */
    public function updatedCinemaId(): void
    {
        $this->reset(['format_id', 'surcharge']);
    }

    public function updatedMovieId(): void
    {
        $this->reset(['format_id', 'surcharge']);
    }

    /** 館を切り替えたとき、前の館で開いていたページ番号が残ると空の一覧になる。 */
    public function updatedSelectedCinemaId(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $admin = $this->currentAdmin();
        $booking = $this->booking_id === null ? null : Booking::findOrFail($this->booking_id);

        if ($booking === null) {
            Gate::forUser($admin)->authorize('create', Booking::class);
        } else {
            Gate::forUser($admin)->authorize('update', $booking);
        }

        $data = $this->validate();

        $error = DB::transaction(function () use ($booking, $data): ?array {
            // A-05（映画の対応規格の更新）と直列化するため、先に対象の映画行を
            // ロックする（4.8.6追記表）。6.6が禁じる組み合わせは
            // 外部キーで表現できないため、チェックと書き込みをこのロックの内側に置く。
            Movie::whereKey((int) $data['movie_id'])->lockForUpdate()->first();

            if ($booking !== null) {
                // A-09（上映回の登録）と直列化するため、編成行もロックする
                // （4.8.6追記表）。ロックの取得順は常に映画→編成とする。
                Booking::whereKey($booking->id)->lockForUpdate()->first();

                if (($screeningError = $this->screeningConstraintError($booking, $data)) !== null) {
                    return $screeningError;
                }
            }

            if (! $this->isFormatSupported((int) $data['cinema_id'], (int) $data['movie_id'], (int) $data['format_id'])) {
                return ['format_id', 'admin.booking.errors.format_not_supported'];
            }

            if ($this->hasOverlappingBooking($data, $booking?->id)) {
                return ['starts_on', 'admin.booking.errors.overlapping'];
            }

            if ($booking === null) {
                Booking::create($data);
            } else {
                $booking->update($data);
            }

            return null;
        });

        if ($error !== null) {
            $this->addError($error[0], __($error[1]));

            return;
        }

        $this->resetForm();
        $this->showForm = false;
        Flux::toast(text: __('admin.booking.messages.saved'), variant: 'success');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * 上映回が既にある編成の編集範囲を検証する（4.8.6追記表）。
     * 違反していれば [エラーを付ける項目, 翻訳キー] を返す。
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}|null
     */
    private function screeningConstraintError(Booking $booking, array $data): ?array
    {
        if (! $booking->screenings()->exists()) {
            return null;
        }

        $changesParent = (int) $data['cinema_id'] !== $booking->cinema_id
            || (int) $data['movie_id'] !== $booking->movie_id
            || (int) $data['format_id'] !== $booking->format_id;

        if ($changesParent) {
            // 4.8.3-3 が上映回の終了時刻を作品の上映時間から確定させ、6.6 が規格を
            // シアターの対応で縛るため、登録済みの上映回がある編成では変更できない。
            return ['movie_id', 'admin.booking.errors.locked_by_screenings'];
        }

        $hasOutOfRangeScreening = $booking->screenings()
            ->where(function ($query) use ($data): void {
                $query->whereDate('starts_at', '<', $data['starts_on'])
                    ->orWhereDate('starts_at', '>', $data['ends_on']);
            })
            ->exists();

        if ($hasOutOfRangeScreening) {
            // 4.8.3-1: 上映回は親の上映期間内であること。
            return ['starts_on', 'admin.booking.errors.screening_out_of_range'];
        }

        return null;
    }

    /**
     * 6.6「双方が一致する組み合わせのみ選択可能」を検証する。
     * `t_bookings` はシアターを持たない（4.8.3）ため、劇場側は
     * 「館内のいずれかのシアターが対応する」で判定する。
     */
    private function isFormatSupported(int $cinemaId, int $movieId, int $formatId): bool
    {
        $movieSupports = Movie::whereKey($movieId)
            ->whereHas('formats', fn ($query) => $query->whereKey($formatId))
            ->exists();

        if (! $movieSupports) {
            return false;
        }

        return Theater::where('cinema_id', $cinemaId)
            ->whereHas('formats', fn ($query) => $query->whereKey($formatId))
            ->exists();
    }

    /**
     * 同一館・同一作品・同一規格で期間が重なる編成の有無を返す（4.8.6追記表）。
     * 作品または規格が異なる編成の同一期間での併存は禁じない。
     *
     * @param  array<string, mixed>  $data
     */
    private function hasOverlappingBooking(array $data, ?int $ignoreBookingId): bool
    {
        return Booking::where('cinema_id', $data['cinema_id'])
            ->where('movie_id', $data['movie_id'])
            ->where('format_id', $data['format_id'])
            ->when($ignoreBookingId !== null, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->where('starts_on', '<=', $data['ends_on'])
            ->where('ends_on', '>=', $data['starts_on'])
            ->exists();
    }

    private function resetForm(): void
    {
        $this->reset(['booking_id', 'hasScreenings', 'cinema_id', 'movie_id', 'format_id', 'starts_on', 'ends_on', 'surcharge']);
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'cinema_id' => ['required', 'integer', Rule::exists(Cinema::class, 'id')],
            'movie_id' => ['required', 'integer', Rule::exists(Movie::class, 'id')],
            'format_id' => ['required', 'integer', Rule::exists(Format::class, 'id')],
            // `date_format` を課すのは、`date` のみだと `09/04/2026` のような表記も
            // 通り、期間の重複判定・上映回の範囲判定がDB側の日付比較に失敗して
            // 無言で素通りするため（保存自体は`date`キャストが解釈して成功する）。
            'starts_on' => ['required', 'date', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'surcharge' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.booking.fields');

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
     * @return LengthAwarePaginator<int, Booking>
     */
    private function visibleBookings(): LengthAwarePaginator
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('viewAny', Booking::class);

        return Booking::with(['cinema', 'movie', 'format'])
            ->withCount('screenings')
            ->when(
                $this->canSelectCinema() && $this->selectedCinemaId !== null,
                fn ($query) => $query->where('cinema_id', $this->selectedCinemaId)
            )
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(20);
    }

    /**
     * 入力中の館と作品の双方が対応する規格（6.6）。いずれかが未選択の間は空とする。
     *
     * @return Collection<int, Format>
     */
    private function selectableFormats(): Collection
    {
        if ($this->cinema_id === '' || $this->movie_id === '') {
            return new Collection;
        }

        return Format::whereHas('movies', fn ($query) => $query->whereKey((int) $this->movie_id))
            ->whereHas('theaters', fn ($query) => $query->where('cinema_id', (int) $this->cinema_id))
            ->orderBy('id')
            ->get();
    }

    public function render(): View
    {
        // 作品の一覧はモーダル（編集権限がある場合のみ描画）でしか使わないため、
        // 閲覧だけの役割では取得しない。
        $canEditAny = Gate::forUser($this->currentAdmin())->allows('updateAny', Booking::class);

        return view('admin.bookings.index', [
            'bookings' => $this->visibleBookings(),
            'cinemas' => $this->canSelectCinema() ? Cinema::visibleTo($this->currentAdmin())->orderBy('id')->get() : new Collection,
            'movies' => $canEditAny ? Movie::orderBy('title')->get() : new Collection,
            'selectableFormats' => $canEditAny ? $this->selectableFormats() : new Collection,
        ])->layout('layouts.admin', ['title' => __('admin.booking.title')]);
    }
}
