<?php

namespace App\Livewire\Admin\Movies;

use App\Models\Admin;
use App\Models\Format;
use App\Models\Movie;
use App\Services\TmdbException;
use App\Services\TmdbMovie;
use App\Services\TmdbService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 映画マスタ管理（A-05）。super-admin が TMDB からの取り込み・編集を行い、
 * cinema-admin は閲覧のみ行う（4.8.2 / 4.8.5）。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる
 * （13.4.2、A-03・A-04・A-06と同じ理由）。
 */
class Index extends Component
{
    use WithPagination;

    /**
     * `<flux:modal wire:model.self="showForm">` がクライアント側（ESC・背景クリック）
     * からの二方向バインディングでこの値を更新するため、Lockedにしない（A-03と同じ）。
     */
    public bool $showForm = false;

    /** TMDB検索モーダル。`showForm`と同じ理由でLockedにしない。 */
    public bool $showImportForm = false;

    #[Locked]
    public bool $readOnly = true;

    #[Locked]
    public ?int $movie_id = null;

    /** 取り込み時に確定し、画面からは変更させない（8.1 / 6.1.2 の一意キー）。 */
    #[Locked]
    public ?int $tmdb_id = null;

    public string $title = '';

    public string $original_title = '';

    public string $synopsis = '';

    public string $poster_path = '';

    public string $runtime_minutes = '';

    public string $released_on = '';

    /** ジャンルはカンマ区切りの文字列で入力し、保存時に配列へ変換する（m_movies.genres は JSON）。 */
    public string $genres = '';

    /** @var array<int, int|string> 対応する上映規格（6.6 / m_movie_format）。 */
    public array $format_ids = [];

    public string $searchQuery = '';

    /**
     * TMDBの応答から組み立てる表示用の値。すべてサーバー側で導出するため
     * クライアントからの書き換えを許さない（`#[Locked]`）。
     *
     * @var array<int, array{tmdb_id: int, title: string, original_title: ?string, released_on: ?string, poster_url: ?string, registered: bool}>
     */
    #[Locked]
    public array $searchResults = [];

    /** 検索の失敗内容（TmdbException 等）。バリデーションエラーではないため errorBag に載せない。 */
    #[Locked]
    public ?string $searchError = null;

    #[Locked]
    public bool $searched = false;

    public function openImport(): void
    {
        Gate::forUser($this->currentAdmin())->authorize('create', Movie::class);

        $this->reset(['searchQuery', 'searchResults', 'searchError', 'searched']);
        $this->showImportForm = true;
    }

    public function search(): void
    {
        Gate::forUser($this->currentAdmin())->authorize('create', Movie::class);

        $this->validate(['searchQuery' => ['required', 'string', 'max:255']], attributes: [
            'searchQuery' => __('admin.movie.fields.searchQuery'),
        ]);

        try {
            $movies = app(TmdbService::class)->search($this->searchQuery);
        } catch (TmdbException $e) {
            $this->searchError = __($e->messageKey);
            $this->searchResults = [];

            return;
        }

        $tmdbIds = array_map(fn (TmdbMovie $movie): int => $movie->tmdbId, $movies);
        /** @var array<int, int> $registeredIds */
        $registeredIds = Movie::whereIn('tmdb_id', $tmdbIds)->pluck('tmdb_id')->all();

        $this->searchResults = array_map(fn (TmdbMovie $movie): array => [
            'tmdb_id' => $movie->tmdbId,
            'title' => $movie->title,
            'original_title' => $movie->originalTitle,
            'released_on' => $movie->releasedOn?->format('Y-m-d'),
            'poster_url' => $movie->posterUrl(),
            'registered' => in_array($movie->tmdbId, $registeredIds, true),
        ], $movies);
        $this->searchError = null;
        $this->searched = true;
    }

    public function import(int $tmdbId): void
    {
        Gate::forUser($this->currentAdmin())->authorize('create', Movie::class);

        if (Movie::where('tmdb_id', $tmdbId)->exists()) {
            $this->searchError = __('admin.movie.errors.already_registered');

            return;
        }

        try {
            $movie = app(TmdbService::class)->find($tmdbId);
        } catch (TmdbException $e) {
            $this->searchError = __($e->messageKey);

            return;
        }

        if ($movie === null) {
            $this->searchError = __('admin.movie.errors.tmdb_not_found');

            return;
        }

        $this->movie_id = null;
        $this->tmdb_id = $movie->tmdbId;
        $this->title = $movie->title;
        $this->original_title = $movie->originalTitle ?? '';
        $this->synopsis = $movie->synopsis;
        $this->poster_path = $movie->posterPath ?? '';
        $this->runtime_minutes = $movie->runtimeMinutes !== null ? (string) $movie->runtimeMinutes : '';
        $this->released_on = $movie->releasedOn?->format('Y-m-d') ?? '';
        $this->genres = implode(', ', $movie->genres);
        $this->format_ids = [];

        $this->resetErrorBag();
        $this->readOnly = false;
        $this->showImportForm = false;
        $this->showForm = true;
    }

    /**
     * 閲覧専用で開く（4.8.2: 映画マスタは cinema-admin に「閲覧」を許可）。
     */
    public function view(int $movieId): void
    {
        Gate::forUser($this->currentAdmin())->authorize('viewAny', Movie::class);

        $movie = Movie::with('formats')->findOrFail($movieId);

        $this->fillForm($movie);
        $this->readOnly = true;
        $this->showForm = true;
    }

    public function edit(int $movieId): void
    {
        $movie = Movie::with('formats')->findOrFail($movieId);

        Gate::forUser($this->currentAdmin())->authorize('update', $movie);

        $this->fillForm($movie);
        $this->readOnly = false;
        $this->showForm = true;
    }

    public function save(): void
    {
        if ($this->tmdb_id === null) {
            // import() または edit() を経ずに呼ばれた場合（例: /livewire/updateへの不正な直接呼び出し）。
            // tmdb_idが未確定のまま保存すると、8.1 / 6.1.2 が一意キーとするTMDBとの対応が失われる。
            return;
        }

        $admin = $this->currentAdmin();
        $movie = $this->movie_id === null ? null : Movie::findOrFail($this->movie_id);

        if ($movie === null) {
            Gate::forUser($admin)->authorize('create', Movie::class);
        } else {
            Gate::forUser($admin)->authorize('update', $movie);
        }

        $data = $this->validate();
        $formatIds = $data['format_ids'] ?? [];
        unset($data['format_ids']);

        $data['original_title'] = $data['original_title'] !== '' ? $data['original_title'] : null;
        $data['poster_path'] = $data['poster_path'] !== '' ? $data['poster_path'] : null;
        $data['genres'] = $this->parseGenres($data['genres'] ?? '');

        $formatInUse = DB::transaction(function () use (&$movie, $data, $formatIds): bool {
            if ($movie !== null) {
                // A-08（上映編成の登録）と直列化するため、対象の映画行をロックしてから
                // 既存の上映編成を確認する（4.8.6追記表）。6.6 は上映編成の登録時に
                // 映画とシアター双方が対応する規格のみ選択可能としており、登録後に
                // 映画側の対応規格を外すと既存の上映編成が設計上ありえない組み合わせになる。
                // この整合は外部キーで表現できないため、確認と同期をロックの内側に置く。
                //
                // `Booking` は `CinemaScope`（13.4.1）の対象だが、この判定は全館の
                // 上映編成を見る必要がある。成立しているのは 4.8.2 が映画マスタの編集を
                // super-admin に限り（`MoviePolicy::update`）、その役割ではスコープが
                // 適用されないため（4.8.6追記表）。
                Movie::whereKey($movie->id)->lockForUpdate()->first();

                if ($movie->bookings()->whereNotIn('format_id', $formatIds)->exists()) {
                    return true;
                }
            }

            if ($movie === null) {
                $movie = Movie::create($data);
            } else {
                $movie->update($data);
            }

            $movie->formats()->sync($formatIds);

            return false;
        });

        if ($formatInUse) {
            $this->addError('format_ids', __('admin.movie.errors.format_in_use'));

            return;
        }

        // 作成直後は編集対象として保持する。これを行わないと、同じモーダルから
        // 再度 save() が呼ばれた際に `tmdb_id` の一意制約違反となり、`tmdb_id` の
        // 入力欄が無い（＝エラーの表示先が無い）ため画面上は無反応になる。
        $this->movie_id = $movie->id;

        $this->showForm = false;
        Flux::toast(text: __('admin.movie.messages.saved'), variant: 'success');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function cancelImport(): void
    {
        $this->reset(['searchQuery', 'searchResults', 'searchError', 'searched']);
        $this->showImportForm = false;
    }

    /**
     * カンマ（全角・半角）区切りのジャンル文字列を配列へ変換する。空要素は除外し、
     * 結果が空になる場合は `null` を返す（`m_movies.genres` は nullable な JSON 列）。
     *
     * @return array<int, string>|null
     */
    private function parseGenres(string $genres): ?array
    {
        // 'u' 修飾子必須: 全角読点「、」はUTF-8で複数バイトのため、'u' が無いと
        // マルチバイト文字の途中のバイトが誤って区切り文字として扱われ、
        // 前後の日本語ジャンル名が不正なUTF-8として壊れる。
        $parts = preg_split('/[,、]/u', $genres) ?: [];
        $parts = array_values(array_unique(array_filter(
            array_map('trim', $parts),
            fn (string $part): bool => $part !== ''
        )));

        return $parts === [] ? null : $parts;
    }

    private function fillForm(Movie $movie): void
    {
        $this->movie_id = $movie->id;
        $this->tmdb_id = $movie->tmdb_id;
        $this->title = $movie->title;
        $this->original_title = $movie->original_title ?? '';
        $this->synopsis = $movie->synopsis;
        $this->poster_path = $movie->poster_path ?? '';
        $this->runtime_minutes = (string) $movie->runtime_minutes;
        $this->released_on = $movie->released_on->format('Y-m-d');
        $this->genres = implode(', ', $movie->genres ?? []);
        $this->format_ids = $movie->formats->pluck('id')->all();
        $this->resetErrorBag();
    }

    private function resetForm(): void
    {
        $this->reset([
            'movie_id', 'tmdb_id', 'readOnly', 'title', 'original_title', 'synopsis',
            'poster_path', 'runtime_minutes', 'released_on', 'genres', 'format_ids',
        ]);
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            // 上限は unsignedInteger の最大値。値の出所はTMDBの応答のみ（`#[Locked]`）だが、
            // 他の数値列（runtime_minutes 等）と同じく桁数超過での500を確実に防ぐ。
            'tmdb_id' => ['required', 'integer', 'min:1', 'max:4294967295', Rule::unique(Movie::class, 'tmdb_id')->ignore($this->movie_id)],
            'title' => ['required', 'string', 'max:255'],
            'original_title' => ['nullable', 'string', 'max:255'],
            // synopsis は TEXT 列。utf8mb4 は1文字最大4バイトのため mb_strlen 基準の上限を 16000 にする
            // （Cinemas\Index の facility_info と同じ理由）。
            'synopsis' => ['required', 'string', 'max:16000'],
            // TMDB が返す先頭スラッシュ付きの相対パスのみ許可する。絶対URLや javascript: を
            // 保存させない（17.5.2-4 と同趣旨）。正規表現は TmdbService::posterUrl() の
            // 判定と共有し、保存側とURL生成側で条件がずれないようにする。
            'poster_path' => ['nullable', 'string', 'max:255', 'regex:'.TmdbService::POSTER_PATH_REGEX],
            // 上限が無いと unsignedInteger の桁数超過で MariaDB の strict モードが500を返す
            // （A-04 の number: max:999、A-06 の default_surcharge: max:10000 と同種）。
            'runtime_minutes' => ['required', 'integer', 'min:1', 'max:1000'],
            // 4.8.5「公開日が過去の作品のみ登録可」。
            'released_on' => ['required', 'date', 'before_or_equal:today'],
            'genres' => ['nullable', 'string', 'max:255'],
            'format_ids' => ['array', 'max:20'],
            'format_ids.*' => ['integer', Rule::exists(Format::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.movie.fields');

        return $attributes;
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    /**
     * @return LengthAwarePaginator<int, Movie>
     */
    private function visibleMovies(): LengthAwarePaginator
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('viewAny', Movie::class);

        /** @var LengthAwarePaginator<int, Movie> $movies */
        $movies = Movie::with('formats')->orderByDesc('released_on')->orderByDesc('id')->paginate(20);

        return $movies;
    }

    public function render(): View
    {
        return view('admin.movies.index', [
            'movies' => $this->visibleMovies(),
            'formats' => Format::orderBy('id')->get(),
        ])->layout('layouts.admin', ['title' => __('admin.movie.title')]);
    }
}
