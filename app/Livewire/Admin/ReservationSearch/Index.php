<?php

namespace App\Livewire\Admin\ReservationSearch;

use App\Enums\ReservationStatus;
use App\Models\Admin;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\User;
use App\Rules\FullWidthKatakana;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 予約検索（A-11）。窓口での問い合わせに対応するため、予約番号・フリガナ・
 * 電話番号のいずれかで予約を検索する（4.8.5）。
 *
 * **表示範囲は 予約番号／予約者名／作品／上映日時／座席／入場状態 に限る。**
 * メールアドレスと決済情報は表示しない（4.8.5-3）。
 *
 * 館の絞り込みは `CinemaScope` 適用済みの `Booking` を `screening.booking` 経由で
 * たどって担保する（4.8.5-4。A-09・A-10 と同じ方式）。
 *
 * **索引の前提**（6.1追記表「インデックスの棚卸し」）:
 * フリガナは前方一致、電話番号・予約番号は完全一致とする（部分一致では索引が効かない）。
 * また `t_reservations` と `users` を横断する検索は `OR` ではなく `UNION` で書く。
 */
class Index extends Component
{
    /** 一度の検索で走査する上限。窓口用途のため、超える場合は絞り込みを促す。 */
    private const int RESULT_LIMIT = 200;

    /**
     * 走査上限を超えたか。`render()` の内側で毎回求め直すため公開プロパティにしない
     * （公開プロパティはクライアントから改変できる）。
     */
    private bool $exceededLimit = false;

    private const int KANA_MIN_LENGTH = 2;

    /** 入力欄。クライアントから任意の値が届くため、検索そのものには使わない。 */
    public string $searchBy = 'reservation_no';

    /** 入力欄。同上。 */
    public string $term = '';

    /**
     * `search()` が検証を通した確定値。**検索はこの2つだけを見る。**
     *
     * 公開プロパティは `wire:model` に無くてもクライアントから更新できるため、
     * 入力欄をそのまま検索に使うと `search()`（唯一 `validate()` を通る経路）を
     * 迂回して `render()` から検索を走らせられる。`term` に `%…` を入れられると
     * 4.8.6追記表「A-11の検索条件と索引の前提」が確定させた前方一致が崩れ、
     * `searchBy` に不正値を入れられると分岐の既定へ落ちる。
     */
    #[Locked]
    public ?string $appliedSearchBy = null;

    #[Locked]
    public string $appliedTerm = '';

    public function updatedSearchBy(): void
    {
        $this->term = '';
        $this->clearResult();
        $this->resetErrorBag();
    }

    public function search(): void
    {
        Gate::forUser($this->currentAdmin())->authorize('viewAny', Reservation::class);

        // 前後の空白を落としてから検証する。`min:2` を未加工の値に掛けると
        // 「カタカナ1文字＋空白」が通過し、1文字での前方一致が成立してしまう。
        $this->term = (string) preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $this->term);

        $validated = $this->validate();

        $this->appliedSearchBy = $validated['searchBy'];
        $this->appliedTerm = $validated['term'];
    }

    public function clear(): void
    {
        $this->reset(['term']);
        $this->clearResult();
        $this->resetErrorBag();
    }

    private function clearResult(): void
    {
        $this->appliedSearchBy = null;
        $this->appliedTerm = '';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'searchBy' => ['required', 'in:reservation_no,kana,phone'],
            'term' => array_merge(['required', 'string', 'max:255'], match ($this->searchBy) {
                // 4.3.5: 8桁の数字。ハイフンの有無を問わず受け付ける。
                'reservation_no' => ['regex:/\A\d{4}-?\d{4}\z/'],
                // 4.3.6: フリガナは全角カタカナのみ。索引を効かせるため前方一致で使う。
                // 文字集合は登録側（P-34・P-02）と同じ定義を使う（旧12章 残課題19）。
                'kana' => [new FullWidthKatakana('admin.reservation_search.errors.kana_only'), 'min:'.self::KANA_MIN_LENGTH],
                // 4.3.6: 電話番号はハイフンなしの半角数字。
                'phone' => ['regex:/\A[0-9]+\z/'],
                default => [],
            }),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            // フリガナは `FullWidthKatakana` が自ら文言キーを持つため、ここには現れない。
            'term.regex' => match ($this->searchBy) {
                'reservation_no' => __('admin.reservation_search.errors.reservation_no_digits'),
                'phone' => __('admin.reservation_search.errors.phone_digits'),
                default => __('validation.regex', ['attribute' => __('admin.reservation_search.fields.term')]),
            },
            'term.min' => __('admin.reservation_search.errors.kana_min'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = __('admin.reservation_search.fields');

        return $fields + ['searchBy' => $fields['search_by']];
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    /**
     * 可視範囲の上映回。親 `Booking` の `CinemaScope` を継承させ、他館を除外する。
     *
     * @return Builder<Screening>
     */
    private function visibleScreeningQuery(): Builder
    {
        // `whereHas()` は関連モデルのグローバルスコープを適用するため、`Booking` の
        // `CinemaScope` によって `cinema-admin` は自館へ自動的に絞られる（4.8.5-4）。
        // A-10 と違い館セレクタを持たないので、画面側に `cinema_id` の条件は書かない
        // （13.4.1「館スコープの絞り込みを個別画面に記述しない」）。
        return Screening::query()->whereHas('booking');
    }

    /**
     * 検索条件に一致する予約のIDを求める。
     *
     * 会員は `users.name_kana` / `users.phone`、非会員は `t_reservations.guest_name_kana` /
     * `guest_phone` が検索対象になる。**両者を `OR` で繋ぐと索引が効かない**ため
     * （6.1追記表）、それぞれ索引を使える別のクエリとして組み `UNION` で束ねる。
     *
     * @return array<int, int>
     */
    private function matchedIds(): array
    {
        $term = $this->appliedTerm;

        if ($this->appliedSearchBy === 'reservation_no') {
            // 4.3.5: 表示形式は4桁ずつのハイフン区切り。入力のハイフンは取り除く。
            return $this->narrowToVisible(Reservation::query()->where('reservation_no', str_replace('-', '', $term)))
                ->limit(self::RESULT_LIMIT + 1)
                ->pluck('id')
                ->all();
        }

        [$guestColumn, $userColumn, $operator, $value] = $this->appliedSearchBy === 'kana'
            ? ['guest_name_kana', 'name_kana', 'like', $term.'%']
            : ['guest_phone', 'phone', '=', $term];

        $guestQuery = $this->narrowToVisible(Reservation::query()->where($guestColumn, $operator, $value))
            ->select('id');

        $memberQuery = $this->narrowToVisible(
            Reservation::query()->whereIn('user_id', User::query()->where($userColumn, $operator, $value)->select('id'))
        )->select('id');

        return $guestQuery
            // `union()` は呼び出し時点のバインドを取り込む一方、SQLは後段で
            // グローバルスコープ適用後に生成される。`Reservation` にスコープが
            // 付いた場合にプレースホルダとバインドがずれるため、素のクエリを渡す。
            ->union($memberQuery->toBase())
            ->limit(self::RESULT_LIMIT + 1)
            ->pluck('id')
            ->all();
    }

    /**
     * 館の可視範囲とステータスで絞る。
     *
     * **走査上限（`RESULT_LIMIT`）を掛ける前に適用すること。** 後に回すと、
     * `cinema-admin` は他館に同姓の予約が上限を超えて存在するだけで
     * 「該当が多すぎます」となり、館で絞る手段が無いため自館の予約に到達できない
     * （4.8.5-4 が自館のみの検索を許可した趣旨に反する）。
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    private function narrowToVisible(Builder $query): Builder
    {
        return $query
            ->whereIn('screening_id', $this->visibleScreeningQuery()->select('id'))
            ->whereIn('status', [ReservationStatus::Paid, ReservationStatus::Cancelled]);
    }

    /**
     * 検索結果。可視範囲の上映回に属する `paid` / `cancelled` の予約のみを返す。
     *
     * @return Collection<int, Reservation>
     */
    private function results(): Collection
    {
        if ($this->appliedSearchBy === null) {
            return new Collection;
        }

        Gate::forUser($this->currentAdmin())->authorize('viewAny', Reservation::class);

        $ids = $this->matchedIds();

        if (count($ids) > self::RESULT_LIMIT) {
            $this->exceededLimit = true;

            return new Collection;
        }

        if ($ids === []) {
            return new Collection;
        }

        // 館とステータスの絞り込みは `matchedIds()`（`narrowToVisible()`）で済んでいる。
        return Reservation::query()
            ->whereIn('id', $ids)
            // 4.8.5-3: メールアドレス・決済情報を扱わないため、必要な列のみ取得する。
            ->select([
                'id', 'reservation_no', 'user_id', 'guest_name',
                'contact_type', 'screening_id', 'status', 'checked_in_at',
            ])
            ->with([
                'user:id,name',
                'screening:id,booking_id,starts_at',
                'screening.booking:id,cinema_id,movie_id',
                'screening.booking.cinema:id,name',
                'screening.booking.movie:id,title',
                // A-10 と同じ理由で `released_at` では絞らない（4.8.6追記表）。
                'seats' => fn ($query) => $query
                    ->select(['id', 'reservation_id', 'seat_id'])
                    ->with('seat:id,row_label,seat_number'),
            ])
            ->orderByDesc('id')
            ->get();
    }

    public function render(): View
    {
        $this->exceededLimit = false;
        $reservations = $this->results();

        return view('admin.reservation-search.index', [
            'reservations' => $reservations,
            'searched' => $this->appliedSearchBy !== null,
            'tooMany' => $this->exceededLimit,
            'resultLimit' => self::RESULT_LIMIT,
        ])->layout('layouts.admin', ['title' => __('admin.reservation_search.title')]);
    }
}
