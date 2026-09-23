<?php

namespace App\Livewire\Admin\Posts;

use App\Enums\PostStatus;
use App\Models\Admin;
use App\Models\Cinema;
use App\Models\Post;
use App\Models\PostCategory;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * お知らせ（A-12）。`super-admin`（全館共通＋任意の館向け）・`cinema-admin`
 * （自館向けのみ）の双方が投稿・編集する（4.8.2）。
 *
 * `c_posts.cinema_id` は「全館共通（NULL）」と「特定館」を兼ねる館のタグである
 * （4.7.1）。`CinemaScope`（13.4.1）を適用すると `cinema_id = ?` の単純一致に
 * なり、全館共通の記事が `cinema-admin` の一覧から消えてしまうため、範囲は
 * `Post::forCinema()`（4.7.1 の抽出条件）で決める。投稿・編集できる**対象館**の
 * 可否は一覧の範囲とは別の判断であり、`PostPolicy::assignCinema()` に寄せる
 * （4.7.4追記表「A-12 の館の範囲」）。画面側は役割名を比較せず、このアビリティの
 * 真偽だけで「全館共通を選べるか」を判定する（13.4.2）。
 *
 * 削除の操作は設けない（6.2「お知らせ・バナーは保持」）。Policyにも `delete` は無い。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる
 * （13.4.2、A-03〜A-09と同じ理由）。
 */
class Index extends Component
{
    use WithPagination;

    /** 一覧の絞り込み。全館を見られる場合のみ選べる（`cinema-admin` は自館固定）。 */
    public ?int $selectedCinemaId = null;

    public ?int $filterCategoryId = null;

    /** `''`（すべて）／`PostStatus` の値。 */
    public string $filterStatus = '';

    // `<flux:modal wire:model.self="showForm">` がクライアント側（ESC・背景クリック）
    // からの二方向バインディングでこの値を更新するため、Lockedにしない。
    public bool $showForm = false;

    #[Locked]
    public ?int $post_id = null;

    public string $category_id = '';

    public string $cinema_id = '';

    public string $title = '';

    public string $body = '';

    public string $status = '';

    public string $published_at = '';

    public function createPost(): void
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('create', Post::class);

        $this->resetForm();
        $this->category_id = (string) (PostCategory::orderBy('id')->value('id') ?? '');
        // 一覧で絞り込んでいる館をそのまま初期値にする（自館固定の場合はその館、
        // 全館表示中の super-admin は全館共通）。
        $this->cinema_id = (string) ($this->targetCinemaId() ?? '');
        $this->status = PostStatus::Draft->value;
        $this->showForm = true;
    }

    public function editPost(int $postId): void
    {
        $admin = $this->currentAdmin();
        $post = $this->findVisiblePost($postId);

        Gate::forUser($admin)->authorize('update', $post);

        $this->post_id = $post->id;
        $this->category_id = (string) $post->category_id;
        $this->cinema_id = (string) ($post->cinema_id ?? '');
        $this->title = $post->title;
        $this->body = $post->body;
        $this->status = $post->status->value;
        $this->published_at = $post->published_at?->format('Y-m-d\TH:i') ?? '';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function updatedSelectedCinemaId(): void
    {
        $this->resetPage();
    }

    public function updatedFilterCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $admin = $this->currentAdmin();
        $post = $this->post_id === null ? null : $this->findVisiblePost($this->post_id);

        if ($post === null) {
            Gate::forUser($admin)->authorize('create', Post::class);
        } else {
            Gate::forUser($admin)->authorize('update', $post);
        }

        $data = $this->validate();

        $cinemaId = $data['cinema_id'] === '' || $data['cinema_id'] === null ? null : (int) $data['cinema_id'];

        // 送信された対象館の可否をここで判定する。`/livewire/update` への直接呼び出しで
        // 他館・全館共通を指定できないようにするため（フォームの表示可否だけでは
        // クライアント側の改変を防げない。13.4.2）。
        Gate::forUser($admin)->authorize('assignCinema', [Post::class, $cinemaId]);

        $publishedAt = null;

        if ($data['published_at'] !== '' && $data['published_at'] !== null) {
            $publishedAt = $this->parseDateTime($data['published_at']);

            if ($publishedAt === null) {
                $this->addError('published_at', __('admin.post.errors.invalid_datetime'));

                return;
            }
        }

        $attributes = [
            'category_id' => (int) $data['category_id'],
            'cinema_id' => $cinemaId,
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => $data['status'],
            'published_at' => $publishedAt,
        ];

        if ($post === null) {
            $created = new Post($attributes);
            // 4.8.4-7 と同じ根拠。マスアサインメントで外部から与えられないよう
            // `#[Fillable]` には含めない。
            $created->created_by_admin_id = $admin->id;
            $created->save();
        } else {
            $post->update($attributes);
        }

        // 一覧はカテゴリー・状態で絞り込まれているため、絞り込みと保存内容が食い違うと
        // 「保存できたのに一覧に出ない」状態になる。保存した内容へ絞り込みを寄せる
        // （A-09 が上映日を寄せているのと同じ趣旨）。
        if ($this->filterCategoryId !== null && $this->filterCategoryId !== $attributes['category_id']) {
            $this->filterCategoryId = $attributes['category_id'];
            $this->resetPage();
        }

        if ($this->filterStatus !== '' && $this->filterStatus !== $attributes['status']) {
            $this->filterStatus = '';
            $this->resetPage();
        }

        // 対象館も同じ理由で寄せる。**全館共通（`null`）へ保存した場合は寄せない**
        // （`forCinema()` はどの館を選んでも全館共通の記事を残すため、一覧から消えない）。
        if ($this->canSelectCinema() && $cinemaId !== null && $this->selectedCinemaId !== null && $this->selectedCinemaId !== $cinemaId) {
            $this->selectedCinemaId = $cinemaId;
            $this->resetPage();
        }

        $this->resetForm();
        $this->showForm = false;
        Flux::toast(text: __('admin.post.messages.saved'), variant: 'success');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * 一覧に出ている記事（`Post::forCinema()` の範囲）として読み直す。他館の記事の
     * IDを直接指定しても、この絞り込みにより到達できない。
     */
    private function findVisiblePost(int $postId): Post
    {
        $post = Post::query()->forCinema($this->targetCinemaId())->whereKey($postId)->first();

        // 存在しない記事と他館の記事を区別しない（17.2.1-2と同じ方針）。
        if ($post === null) {
            abort(404);
        }

        return $post;
    }

    /**
     * `datetime-local` の入力値を解釈する。秒の有無はブラウザにより異なる
     * （A-09 と同じ実装）。
     */
    private function parseDateTime(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed->format($format) === $value) {
                return $parsed->seconds(0);
            }
        }

        return null;
    }

    private function resetForm(): void
    {
        $this->reset(['post_id', 'category_id', 'cinema_id', 'title', 'body', 'status', 'published_at']);
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists(PostCategory::class, 'id')],
            'cinema_id' => ['nullable', 'integer', Rule::exists(Cinema::class, 'id')],
            'title' => ['required', 'string', 'max:255'],
            // `c_posts.body` は TEXT（65,535バイト）。4バイト文字だけで構成されても
            // 収まる上限として16,000文字とする（4.7.4追記表）。
            'body' => ['required', 'string', 'max:16000'],
            'status' => ['required', Rule::enum(PostStatus::class)],
            'published_at' => [
                Rule::requiredIf(fn () => $this->status === PostStatus::Published->value),
                'nullable',
                'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.post.fields');

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
     * 絞り込みの対象となる館。全館を見られない場合は自館固定（4.8.5）。
     * 全館を見られる場合、未選択なら `null`（全館。`forCinema(null)` は絞り込み
     * 自体を行わない）。
     *
     * `Post` には `CinemaScope` が付いていないため、所属館が未設定のまま全館横断を
     * 許されない管理者を素通しすると `forCinema(null)` が全館の記事を返す。
     * `CinemaScope` / `Cinema::visibleTo()` と同じく403として即座に検出する。
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
     * @return LengthAwarePaginator<int, Post>
     */
    private function visiblePosts(): LengthAwarePaginator
    {
        $admin = $this->currentAdmin();

        // `/livewire/update` 経由の直接呼び出しに備える（A-09と同じ理由）。
        Gate::forUser($admin)->authorize('viewAny', Post::class);

        return Post::query()
            ->forCinema($this->targetCinemaId())
            ->when($this->filterCategoryId !== null, fn ($query) => $query->where('category_id', $this->filterCategoryId))
            // 検索パラメータを許可値と照合してから渡す（17.5.1-2）。
            ->when(
                ($filterStatus = PostStatus::tryFrom($this->filterStatus)) !== null,
                fn ($query) => $query->where('status', $filterStatus)
            )
            ->with(['category', 'cinema'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(20);
    }

    /**
     * 投稿・編集の対象館として選べる館。全館共通（NULL）を選べる管理者にのみ
     * 選択肢を出す（`cinema-admin` は自館固定であり、モーダルのセレクタ自体を
     * 描かない）。一覧の絞り込み（`$cinemas`、`viewAllCinemas`）とは判定の出所を
     * 分ける。両者は現状の Policy では一致するが、意味が異なる（見える範囲と
     * 投稿できる範囲）ため、片方の変更が他方を無言で壊さないようにする。
     *
     * @return Collection<int, Cinema>
     */
    private function assignableCinemas(): Collection
    {
        $admin = $this->currentAdmin();

        if (! Gate::forUser($admin)->allows('assignCinema', [Post::class, null])) {
            return new Collection;
        }

        return Cinema::visibleTo($admin)->orderBy('id')->get();
    }

    public function render(): View
    {
        return view('admin.posts.index', [
            'posts' => $this->visiblePosts(),
            'categories' => PostCategory::orderBy('id')->get(),
            'cinemas' => $this->canSelectCinema() ? Cinema::visibleTo($this->currentAdmin())->orderBy('id')->get() : new Collection,
            'assignableCinemas' => $this->assignableCinemas(),
        ])->layout('layouts.admin', ['title' => __('admin.post.title')]);
    }
}
