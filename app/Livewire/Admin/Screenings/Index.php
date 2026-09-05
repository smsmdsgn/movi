<?php

namespace App\Livewire\Admin\Screenings;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Screening;
use App\Models\SeatLock;
use App\Models\Theater;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 上映回（A-09）。`super-admin`（全館）・`cinema-admin`（自館）の双方が、
 * 上映編成にぶら下がる個々の回を登録・編集・削除する（4.8.2 / 4.8.3）。
 *
 * 入力は「上映編成・シアター・開始日時」の3項目とし、作品・規格・上映期間は
 * 親から導出する（4.8.3の【根拠】）。終了時刻は 4.8.3-3 の式で流し込み、手動で
 * 上書きできる。
 *
 * `t_screenings` は `cinema_id` を持たず `CinemaScope`（13.4.1）を適用できない。
 * 館の範囲は、いずれも `CinemaScope` 適用済みの親（`Booking`・`Theater`）を
 * 経由することで担保する（4.8.6追記表「A-09の館スコープ」。A-04 が `Seat` に
 * ついて採ったのと同型）。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる
 * （13.4.2、A-03〜A-08と同じ理由）。
 */
class Index extends Component
{
    use WithPagination;

    /** 一覧の絞り込み。`super-admin` のみ館を選べる（`cinema-admin` は自館固定）。 */
    public ?int $selectedCinemaId = null;

    /** 一覧の絞り込み（上映日）。既定は本日（4.8.6追記表）。 */
    public string $filterDate = '';

    public ?int $filterTheaterId = null;

    /**
     * `wire:model.self` でモーダルと二方向バインドするため `#[Locked]` にできない
     * （A-03〜A-08と同じ）。更新対象は `screening_id`（`#[Locked]`）が特定する。
     */
    public bool $showForm = false;

    #[Locked]
    public ?int $screening_id = null;

    public string $booking_id = '';

    public string $theater_id = '';

    public string $starts_at = '';

    public string $ends_at = '';

    public function mount(): void
    {
        $this->filterDate = Date::now()->toDateString();
    }

    public function createScreening(): void
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('create', Screening::class);

        $this->resetForm();
        // 一覧で見ている日をそのまま初期値にする（現場は「その日の時間割」を単位に操作する）。
        $this->starts_at = $this->selectedDate()->setTime(10, 0)->format('Y-m-d\TH:i');
        $this->showForm = true;
    }

    public function editScreening(int $screeningId): void
    {
        $admin = $this->currentAdmin();
        $screening = $this->findVisibleScreening($screeningId);

        Gate::forUser($admin)->authorize('update', $screening);

        if ($screening->reservations()->exists()) {
            // 予約が存在する上映回は編集できない（4.8.6追記表）。一覧のボタンも
            // 出さないが、`/livewire/update` への直接呼び出しに備えてここでも拒む。
            Flux::toast(text: __('admin.screening.errors.locked_by_reservations'), variant: 'danger');

            return;
        }

        $this->screening_id = $screening->id;
        $this->booking_id = (string) $screening->booking_id;
        $this->theater_id = (string) $screening->theater_id;
        $this->starts_at = $screening->starts_at->format('Y-m-d\TH:i');
        $this->ends_at = $screening->ends_at->format('Y-m-d\TH:i');
        $this->resetErrorBag();
        $this->showForm = true;
    }

    /**
     * 上映編成を選び直すと、選べるシアター（その編成の館・規格に対応するもの）が
     * 変わる。選択肢から外れた値が残らないようシアターを落とし、終了時刻を
     * 新しい作品の上映時間で引き直す。
     */
    public function updatedBookingId(): void
    {
        $this->reset('theater_id');
        $this->fillEndsAt();
    }

    /** 4.8.3-3 の自動計算。手動上書き可のため `updated` フック（画面操作時のみ）で行う。 */
    public function updatedStartsAt(): void
    {
        $this->fillEndsAt();
    }

    public function updatedSelectedCinemaId(): void
    {
        $this->reset('filterTheaterId');
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

    public function save(): void
    {
        $admin = $this->currentAdmin();
        $screening = $this->screening_id === null ? null : $this->findVisibleScreening($this->screening_id);

        if ($screening === null) {
            Gate::forUser($admin)->authorize('create', Screening::class);
        } else {
            Gate::forUser($admin)->authorize('update', $screening);
        }

        $data = $this->validate();

        $error = DB::transaction(function () use ($admin, $screening, $data): ?array {
            // ロックの取得順は 映画（A-05・A-08）→ 編成（A-08・A-09）→ シアター（A-09）で
            // 固定する（4.8.6追記表）。A-09 は映画行に触れないため編成から取る。
            $booking = Booking::whereKey((int) $data['booking_id'])->lockForUpdate()->first();

            if ($booking === null) {
                // `CinemaScope` により他館の編成はここで消える（`Rule::exists` は
                // グローバルスコープを適用しないため、館の判定はこの位置で行う）。
                return ['booking_id', 'admin.screening.errors.booking_not_found'];
            }

            // 4.8.3-4（同一シアターのインターバル）は編成をまたいでシアター単位に
            // 働くため、編成行のロックだけでは別編成からの同時挿入を直列化できない。
            $theater = Theater::whereKey((int) $data['theater_id'])->lockForUpdate()->first();

            if ($theater === null) {
                return ['theater_id', 'admin.screening.errors.theater_not_found'];
            }

            if ($screening !== null && $screening->reservations()->exists()) {
                return ['starts_at', 'admin.screening.errors.locked_by_reservations'];
            }

            $startsAt = $this->parseDateTime($data['starts_at']);
            $endsAt = $this->parseDateTime($data['ends_at']);

            if ($startsAt === null || $endsAt === null) {
                return ['starts_at', 'admin.screening.errors.invalid_datetime'];
            }

            if (($constraintError = $this->constraintError($booking, $theater, $startsAt, $endsAt, $screening?->id)) !== null) {
                return $constraintError;
            }

            $attributes = [
                'booking_id' => $booking->id,
                'theater_id' => $theater->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];

            if ($screening === null) {
                $created = new Screening($attributes);
                // 4.8.4-7 の【根拠】が参照する登録者。マスアサインメントで外部から
                // 与えられないよう `#[Fillable]` には含めない（4.8.6追記表）。
                $created->created_by_admin_id = $admin->id;
                $created->save();
            } else {
                $screening->update($attributes);
            }

            return null;
        });

        if ($error !== null) {
            $this->addError($error[0], __($error[1]));

            return;
        }

        // 一覧は上映日で絞り込まれているため、絞り込み日と異なる日の回を登録すると
        // 保存できたのに一覧へ現れない。登録した日へ絞り込みを寄せる。
        $savedOn = $this->parseDateTime($data['starts_at'])?->toDateString();

        if ($savedOn !== null && $savedOn !== $this->selectedDate()->toDateString()) {
            $this->filterDate = $savedOn;
            $this->resetPage();
        }

        $this->resetForm();
        $this->showForm = false;
        Flux::toast(text: __('admin.screening.messages.saved'), variant: 'success');
    }

    /**
     * 上映回を削除する（6.2 制約1）。開始前の回で、予約が1件も無く、決済中の
     * 座席ロックも無い場合に限る。判定と削除は保存と同じロックの内側で行う。
     */
    public function deleteScreening(int $screeningId): void
    {
        $admin = $this->currentAdmin();
        $screening = $this->findVisibleScreening($screeningId);

        Gate::forUser($admin)->authorize('delete', $screening);

        $error = DB::transaction(function () use ($screening): ?string {
            Booking::whereKey($screening->booking_id)->lockForUpdate()->first();
            Theater::whereKey($screening->theater_id)->lockForUpdate()->first();

            if ($screening->starts_at->isPast()) {
                // 6.2「上映編成・上映回は上映期間終了後も保持」。削除は登録の誤りを
                // 正すための操作であり、上映実績を消す手段ではない。A-04 が 6.2 制約2
                // を未来の上映回に限って判定しているのと同じ切り分け（4.8.6追記表）。
                return 'admin.screening.errors.already_started';
            }

            if ($screening->reservations()->exists()) {
                // 6.2 制約1。`t_reservations.screening_id` は `restrictOnDelete` の
                // ため最終的にはDBも拒むが、500ではなく画面上のエラーとして返す。
                return 'admin.screening.errors.locked_by_reservations';
            }

            if (SeatLock::where('screening_id', $screening->id)->where('expires_at', '>', Date::now())->exists()) {
                // 決済中（`pending`）の座席は `t_reservation_seats` をまだ持たない
                // （6.4.2）。`t_seat_locks.screening_id` は `cascadeOnDelete` であり、
                // ここで止めなければ顧客のロックが無言で消える（4.8.6追記表）。
                return 'admin.screening.errors.locked_by_seat_locks';
            }

            $screening->delete();

            return null;
        });

        if ($error !== null) {
            Flux::toast(text: __($error), variant: 'danger');

            return;
        }

        Flux::toast(text: __('admin.screening.messages.deleted'), variant: 'success');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * 4.8.3 のバリデーション1・2・4・5 を、ロックの内側で判定する（13.4.4の例外）。
     * 違反していれば [エラーを付ける項目, 翻訳キー] を返す。
     *
     * @return array{0: string, 1: string}|null
     */
    private function constraintError(
        Booking $booking,
        Theater $theater,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $ignoreScreeningId,
    ): ?array {
        if ($theater->cinema_id !== $booking->cinema_id) {
            // 4.8.3-5。複合外部キーで表現できないため実装で担保する（6.1追記表）。
            return ['theater_id', 'admin.screening.errors.theater_cinema_mismatch'];
        }

        if (! $theater->formats()->whereKey($booking->format_id)->exists()) {
            // 4.8.3-2。
            return ['theater_id', 'admin.screening.errors.format_not_supported'];
        }

        $startsOn = $startsAt->toDateString();

        if ($startsOn < $booking->starts_on->toDateString() || $startsOn > $booking->ends_on->toDateString()) {
            // 4.8.3-1。A-08 の期間の判定（`whereDate('starts_at')`）と同じく開始日で見る。
            return ['starts_at', 'admin.screening.errors.out_of_booking_range'];
        }

        if ($this->hasIntervalConflict($theater->id, $startsAt, $endsAt, $ignoreScreeningId)) {
            return ['starts_at', 'admin.screening.errors.interval_conflict'];
        }

        return null;
    }

    /**
     * 4.8.3-4: 同一シアターで前の回の終了時刻から30分以上空いていること。
     * 前後どちらの隣接も対象とするため、新しい回の区間を前後に30分広げ、
     * 既存の回と重なるものがあるかで判定する。
     */
    private function hasIntervalConflict(
        int $theaterId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $ignoreScreeningId,
    ): bool {
        return Screening::where('theater_id', $theaterId)
            ->when($ignoreScreeningId !== null, fn ($query) => $query->whereKeyNot($ignoreScreeningId))
            ->where('starts_at', '<', $endsAt->addMinutes(Screening::INTERVAL_MINUTES))
            ->where('ends_at', '>', $startsAt->subMinutes(Screening::INTERVAL_MINUTES))
            ->exists();
    }

    /**
     * 親の上映期間・館の範囲を確認したうえで上映回を取得する。
     * `cinema-admin` が他館のIDを直接指定した場合、親（`CinemaScope` 適用済み）が
     * 見つからず `ModelNotFoundException` になる（17.2.1）。
     */
    private function findVisibleScreening(int $screeningId): Screening
    {
        /** @var Screening $screening */
        $screening = Screening::whereKey($screeningId)
            ->whereHas('booking')
            ->firstOrFail();

        return $screening;
    }

    /** 4.8.3-3 の「開始時刻 + 作品の上映時間 + 予告編15分」を終了時刻へ流し込む。 */
    private function fillEndsAt(): void
    {
        $startsAt = $this->parseDateTime($this->starts_at);
        $booking = $this->booking_id === '' ? null : Booking::with('movie')->find((int) $this->booking_id);

        if ($startsAt === null || $booking === null) {
            return;
        }

        $this->ends_at = $startsAt
            ->addMinutes($booking->movie->runtime_minutes + Screening::TRAILER_MINUTES)
            ->format('Y-m-d\TH:i');
    }

    /**
     * `datetime-local` の入力値を解釈する。秒の有無はブラウザにより異なる。
     */
    private function parseDateTime(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            // `createFromFormat` は `2026-09-31T10:00` のような日付を翌月へ繰り上げて
            // 解釈するため、往復させて入力と一致することを確かめる。
            if ($parsed->format($format) === $value) {
                return $parsed->seconds(0);
            }
        }

        return null;
    }

    /** 一覧が対象とする上映日。不正な値・空文字は本日として扱う（4.8.6追記表）。 */
    private function selectedDate(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $this->filterDate)->startOfDay();
        } catch (\Throwable) {
            return Date::now()->toImmutable()->startOfDay();
        }
    }

    private function resetForm(): void
    {
        $this->reset(['screening_id', 'booking_id', 'theater_id', 'starts_at', 'ends_at']);
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'booking_id' => ['required', 'integer', Rule::exists(Booking::class, 'id')],
            'theater_id' => ['required', 'integer', Rule::exists(Theater::class, 'id')],
            // 秒を含む表記も受ける（`datetime-local` の値はブラウザにより異なる）。
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.screening.fields');

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
     * 絞り込みの対象となる館。`cinema-admin` は自館固定（4.8.5）。
     * `super-admin` が未選択の場合は `null`（全館）。
     */
    private function targetCinemaId(): ?int
    {
        return $this->canSelectCinema()
            ? $this->selectedCinemaId
            : $this->currentAdmin()->cinema_id;
    }

    /**
     * @return LengthAwarePaginator<int, Screening>
     */
    private function visibleScreenings(): LengthAwarePaginator
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('viewAny', Screening::class);

        $cinemaId = $this->targetCinemaId();

        return Screening::query()
            // 親 `Booking` の `CinemaScope` を継承させ、他館の回を除外する。
            ->whereHas('booking', fn ($query) => $query->when(
                $cinemaId !== null,
                fn ($booking) => $booking->where('cinema_id', $cinemaId)
            ))
            ->with(['booking.cinema', 'booking.movie', 'booking.format', 'theater'])
            ->withCount('reservations')
            ->whereDate('starts_at', $this->selectedDate()->toDateString())
            ->when($this->filterTheaterId !== null, fn ($query) => $query->where('theater_id', $this->filterTheaterId))
            ->orderBy('starts_at')
            ->orderBy('theater_id')
            ->paginate(20);
    }

    /**
     * 一覧の絞り込み用のシアター。館が定まらない間（`super-admin` が全館を
     * 見ている間）は選択肢を出さない（全7館45シアターが並ぶため）。
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

    /**
     * 入力中の開始日を上映期間に含む上映編成（4.8.3-1）。開始日が未入力の間は
     * 一覧で見ている日を用いる。作品・規格・期間はここから導出される。
     *
     * @return Collection<int, Booking>
     */
    private function selectableBookings(): Collection
    {
        $date = ($this->parseDateTime($this->starts_at) ?? $this->selectedDate())->toDateString();
        $cinemaId = $this->targetCinemaId();

        return Booking::with(['cinema', 'movie', 'format'])
            ->when($cinemaId !== null, fn ($query) => $query->where('cinema_id', $cinemaId))
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->orderBy('cinema_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * 選択中の上映編成の館に属し、その規格に対応するシアター（4.8.3-2 / 4.8.3-5）。
     * 編成が未選択の間は空とする。
     *
     * @return Collection<int, Theater>
     */
    private function selectableTheaters(): Collection
    {
        $booking = $this->booking_id === '' ? null : Booking::find((int) $this->booking_id);

        if ($booking === null) {
            return new Collection;
        }

        return Theater::where('cinema_id', $booking->cinema_id)
            ->whereHas('formats', fn ($query) => $query->whereKey($booking->format_id))
            ->orderBy('number')
            ->get();
    }

    public function render(): View
    {
        $canEditAny = Gate::forUser($this->currentAdmin())->allows('updateAny', Screening::class);

        return view('admin.screenings.index', [
            'screenings' => $this->visibleScreenings(),
            'cinemas' => $this->canSelectCinema() ? Cinema::visibleTo($this->currentAdmin())->orderBy('id')->get() : new Collection,
            'filterTheaters' => $this->filterTheaters(),
            // 登録モーダル（編集権限がある場合のみ描画）でしか使わない。
            'selectableBookings' => $canEditAny ? $this->selectableBookings() : new Collection,
            'selectableTheaters' => $canEditAny ? $this->selectableTheaters() : new Collection,
        ])->layout('layouts.admin', ['title' => __('admin.screening.title')]);
    }
}
