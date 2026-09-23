<?php

namespace App\Livewire\Admin\Banners;

use App\Enums\BannerPosition;
use App\Livewire\Admin\Concerns\ParsesDateTimeInput;
use App\Models\Admin;
use App\Models\Banner;
use App\Models\Cinema;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * バナー（A-13）。掲載位置ごとの画像・リンク先・掲載期間を管理する（4.7.2）。
 *
 * 「権限: `super-admin` のみ」（4.7.2）であり、`cinema-admin` は
 * `view-admin-screen` Gate（`AppServiceProvider`）の時点で到達できない。この画面には
 * `cinema-admin` が来ないため、他画面（A-12等）のように館セレクタの表示可否を
 * `viewAllCinemas` で分岐する必要が無い。ただし `/livewire/update` への直接呼び出しに
 * 備え、`BannerPolicy` の判定は本コンポーネントでも都度行う（13.4.2、A-12と同じ理由）。
 *
 * 削除の操作は設けない（6.2「お知らせ・バナーは保持」。`BannerPolicy` にも `delete`
 * は無い）。
 *
 * 画像は `storage/app/public/banners` に保存し、ファイル名は
 * `TemporaryUploadedFile::store()` がフレームワーク側で採番する（アップロード時の
 * 名称を使わない。17.6-3・4）。編集で画像を差し替えた場合、**新しい画像の保存が
 * 成功した後に**旧ファイルを削除する。保存前に削除すると、検証エラー等で保存が
 * 失敗した際に画像を失う。旧パスがシーダー投入のダミーで実在しない場合、削除は
 * 何も起こさない（`Banner::hasImage()` と同じ前提）。
 */
class Index extends Component
{
    use ParsesDateTimeInput;
    use WithFileUploads;
    use WithPagination;

    /** `''`（すべて）／`BannerPosition` の値。 */
    public string $filterPosition = '';

    public ?int $selectedCinemaId = null;

    // `<flux:modal wire:model.self="showForm">` がクライアント側（ESC・背景クリック）
    // からの二方向バインディングでこの値を更新するため、Lockedにしない。
    public bool $showForm = false;

    #[Locked]
    public ?int $banner_id = null;

    public string $position = '';

    public ?TemporaryUploadedFile $image = null;

    public string $link_url = '';

    public string $alt = '';

    public string $sort_order = '1';

    public string $starts_at = '';

    public string $ends_at = '';

    public string $cinema_id = '';

    public function updatedFilterPosition(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCinemaId(): void
    {
        $this->resetPage();
    }

    public function createBanner(): void
    {
        Gate::forUser($this->currentAdmin())->authorize('create', Banner::class);

        $this->resetForm();
        $this->position = '';
        $this->sort_order = '1';
        $this->cinema_id = (string) ($this->selectedCinemaId ?? '');
        $this->showForm = true;
    }

    public function editBanner(int $bannerId): void
    {
        $admin = $this->currentAdmin();
        $banner = $this->findVisibleBanner($bannerId);

        Gate::forUser($admin)->authorize('update', $banner);

        $this->banner_id = $banner->id;
        $this->position = $banner->position->value;
        $this->link_url = $banner->link_url ?? '';
        $this->alt = $banner->alt;
        $this->sort_order = (string) $banner->sort_order;
        $this->starts_at = $banner->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->ends_at = $banner->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->cinema_id = (string) ($banner->cinema_id ?? '');
        // 差し替えないときは既存の画像パスを保つため、null のままにする。
        $this->image = null;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $admin = $this->currentAdmin();
        $banner = $this->banner_id === null ? null : $this->findVisibleBanner($this->banner_id);

        if ($banner === null) {
            Gate::forUser($admin)->authorize('create', Banner::class);
        } else {
            Gate::forUser($admin)->authorize('update', $banner);
        }

        $data = $this->validate();

        $startsAt = null;

        if ($data['starts_at'] !== '' && $data['starts_at'] !== null) {
            $startsAt = $this->parseDateTime($data['starts_at']);

            if ($startsAt === null) {
                $this->addError('starts_at', __('admin.banner.errors.invalid_datetime'));

                return;
            }
        }

        $endsAt = null;

        if ($data['ends_at'] !== '' && $data['ends_at'] !== null) {
            $endsAt = $this->parseDateTime($data['ends_at']);

            if ($endsAt === null) {
                $this->addError('ends_at', __('admin.banner.errors.invalid_datetime'));

                return;
            }
        }

        $oldImagePath = null;
        $imagePath = $banner?->image_path;

        if ($this->image !== null) {
            $oldImagePath = $banner?->image_path;
            $imagePath = $this->image->store('banners', 'public');
        }

        $attributes = [
            'position' => $data['position'],
            'image_path' => $imagePath,
            'link_url' => $data['link_url'] !== '' ? $data['link_url'] : null,
            'alt' => $data['alt'],
            'sort_order' => (int) $data['sort_order'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'cinema_id' => $data['cinema_id'] === '' || $data['cinema_id'] === null ? null : (int) $data['cinema_id'],
        ];

        if ($banner === null) {
            $banner = new Banner($attributes);
            $banner->save();
        } else {
            $banner->update($attributes);
        }

        // 新しい画像の保存（Eloquentへの反映）が成功した後に旧ファイルを消す。
        // 保存より前に消すと、検証エラー等で保存に失敗した際に画像を失う。
        if ($oldImagePath !== null) {
            Storage::disk('public')->delete($oldImagePath);
        }

        // 一覧は掲載位置・対象館で絞り込まれているため、絞り込みと保存内容が食い違うと
        // 「保存できたのに一覧に出ない」状態になる。保存した内容へ絞り込みを寄せる
        // （A-09・A-12 と同じ趣旨）。
        if ($this->filterPosition !== '' && $this->filterPosition !== $attributes['position']) {
            $this->filterPosition = $attributes['position'];
            $this->resetPage();
        }

        // 対象館も同じ理由で寄せる。**全館共通（`null`）へ保存した場合は寄せない**
        // （`forCinema()` はどの館を選んでも全館共通のバナーを残すため、一覧から
        // 消えない。A-12 の `save()` と同じ方針）。
        if ($attributes['cinema_id'] !== null && $this->selectedCinemaId !== null && $this->selectedCinemaId !== $attributes['cinema_id']) {
            $this->selectedCinemaId = $attributes['cinema_id'];
            $this->resetPage();
        }

        $this->resetForm();
        $this->showForm = false;
        Flux::toast(text: __('admin.banner.messages.saved'), variant: 'success');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * 一覧に出ているバナー（`Banner::forCinema()` の範囲）として読み直す。他館の
     * バナーのIDを直接指定しても、この絞り込みにより到達できない（17.2.1-2と同じ方針）。
     */
    private function findVisibleBanner(int $bannerId): Banner
    {
        $banner = Banner::query()->forCinema($this->selectedCinemaId)->whereKey($bannerId)->first();

        if ($banner === null) {
            abort(404);
        }

        return $banner;
    }

    private function resetForm(): void
    {
        $this->reset(['banner_id', 'position', 'image', 'link_url', 'alt', 'sort_order', 'starts_at', 'ends_at', 'cinema_id']);
        $this->resetErrorBag();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $endsAtRules = ['nullable', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s'];

        if ($this->starts_at !== '') {
            $endsAtRules[] = 'after:starts_at';
        }

        return [
            'position' => ['required', Rule::enum(BannerPosition::class)],
            'image' => [
                $this->banner_id === null ? 'required' : 'nullable',
                'image',
                // 拡張子とMIMEタイプの双方を検証する（17.6-1）。許可する形式は
                // jpg / png / webp / avif のみで、SVGは許可しない（17.6-2）。
                'mimes:jpg,jpeg,png,webp,avif',
                'mimetypes:image/jpeg,image/png,image/webp,image/avif',
                'max:2048',
            ],
            // スキームを http / https に限定する（17.5.2-4）。
            'link_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'alt' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s'],
            'ends_at' => $endsAtRules,
            'cinema_id' => ['nullable', 'integer', Rule::exists(Cinema::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.banner.fields');

        return $attributes;
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    /**
     * @return LengthAwarePaginator<int, Banner>
     */
    private function visibleBanners(): LengthAwarePaginator
    {
        $admin = $this->currentAdmin();

        // `/livewire/update` 経由の直接呼び出しに備える（A-09・A-12と同じ理由）。
        Gate::forUser($admin)->authorize('viewAny', Banner::class);

        $positions = array_column(BannerPosition::cases(), 'value');
        $placeholders = implode(',', array_fill(0, count($positions), '?'));

        return Banner::query()
            ->forCinema($this->selectedCinemaId)
            // 検索パラメータを許可値と照合してから渡す（17.5.1-2）。
            ->when(
                ($filterPosition = BannerPosition::tryFrom($this->filterPosition)) !== null,
                fn ($query) => $query->where('position', $filterPosition)
            )
            ->with('cinema')
            // 4.7.2 の掲載位置の順（main → carousel → sub → footer_link）で並べる。
            // MariaDB固定のためFIELD()を使う（3.2）。値は必ずバインドで渡す。
            ->orderByRaw("FIELD(position, {$placeholders})", $positions)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);
    }

    public function render(): View
    {
        $admin = $this->currentAdmin();

        return view('admin.banners.index', [
            'banners' => $this->visibleBanners(),
            'cinemas' => Cinema::visibleTo($admin)->orderBy('id')->get(),
            // 編集モーダルの「現在の画像」表示用。読み直しにも一覧と同じ範囲
            // （`forCinema()`）を通し、スコープ外の行を表示に使わない。
            'editingBanner' => $this->banner_id === null
                ? null
                : Banner::query()->forCinema($this->selectedCinemaId)->whereKey($this->banner_id)->first(),
        ])->layout('layouts.admin', ['title' => __('admin.banner.title')]);
    }
}
