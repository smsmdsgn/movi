<?php

namespace App\Livewire\Admin\Cinemas;

use App\Models\Admin;
use App\Models\Cinema;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 館マスタ管理（A-03）。super-admin が作成・編集し、cinema-admin は自館のみ閲覧する（4.8.2）。
 * 閲覧（詳細表示）と編集を同一のモーダルで扱い、`$readOnly` で入力欄と保存操作の可否を切り替える。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる。
 * `AuthorizesRequests::authorize()` は既定ガード（`web`）のユーザーを解決するため、
 * `admin` ガードでは常に未許可と判定される（13.4.2、AppServiceProviderと同じ理由）。
 */
class Index extends Component
{
    // `<flux:modal wire:model.self="showForm">` がクライアント側（ESC・背景クリック）
    // からの二方向バインディングでこの値を更新するため、Lockedにしない。
    public bool $showForm = false;

    #[Locked]
    public bool $readOnly = true;

    #[Locked]
    public ?int $cinema_id = null;

    public string $slug = '';

    public string $name = '';

    public string $concept = '';

    public string $address = '';

    public string $phone = '';

    public string $business_hours = '';

    public string $facility_info = '';

    public string $access_note = '';

    public string $map_embed_url = '';

    public function create(): void
    {
        Gate::forUser($this->currentAdmin())->authorize('create', Cinema::class);

        $this->resetForm();
        $this->readOnly = false;
        $this->showForm = true;
    }

    /**
     * 閲覧専用で開く（4.8.2: 館マスタは cinema-admin に「閲覧」を許可）。
     * 自館以外を閲覧できないよう、一覧と同じ `visibleTo` スコープ経由で取得する。
     */
    public function view(int $cinemaId): void
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('viewAny', Cinema::class);

        $cinema = Cinema::visibleTo($admin)->findOrFail($cinemaId);

        $this->fillForm($cinema);
        $this->readOnly = true;
        $this->showForm = true;
    }

    public function edit(int $cinemaId): void
    {
        $cinema = Cinema::findOrFail($cinemaId);

        Gate::forUser($this->currentAdmin())->authorize('update', $cinema);

        $this->fillForm($cinema);
        $this->readOnly = false;
        $this->showForm = true;
    }

    public function save(): void
    {
        $admin = $this->currentAdmin();

        if ($this->cinema_id === null) {
            Gate::forUser($admin)->authorize('create', Cinema::class);

            $data = $this->validate();

            Cinema::create($data);
        } else {
            $cinema = Cinema::findOrFail($this->cinema_id);

            Gate::forUser($admin)->authorize('update', $cinema);

            $data = $this->validate();

            $cinema->update($data);
        }

        $this->showForm = false;
        Flux::toast(text: __('admin.cinema.messages.saved'), variant: 'success');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/\A'.Cinema::SLUG_REGEX.'\z/',
                Rule::unique(Cinema::class, 'slug')->ignore($this->cinema_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'concept' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'business_hours' => ['required', 'string', 'max:255'],
            // TEXT列は65535バイトだが、utf8mb4は1文字最大4バイトのためmb_strlen基準では
            // 65535を上限にできない（4バイト文字のみだと約16383文字で超過する）。
            'facility_info' => ['required', 'string', 'max:16000'],
            'access_note' => ['required', 'string', 'max:16000'],
            // 17.7 のCSP（frame-src）が https://www.google.com のみを許可するため、
            // 埋め込み先のホストをそれ以外に広げない（4.1.1: Googleマップの埋め込み）。
            'map_embed_url' => ['required', 'url', 'max:2048', 'regex:#\Ahttps://www\.google\.com/#'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.cinema.fields');

        return $attributes;
    }

    private function fillForm(Cinema $cinema): void
    {
        $this->cinema_id = $cinema->id;
        $this->slug = $cinema->slug;
        $this->name = $cinema->name;
        $this->concept = $cinema->concept;
        $this->address = $cinema->address;
        $this->phone = $cinema->phone;
        $this->business_hours = $cinema->business_hours;
        $this->facility_info = $cinema->facility_info;
        $this->access_note = $cinema->access_note;
        $this->map_embed_url = $cinema->map_embed_url;
        $this->resetErrorBag();
    }

    private function resetForm(): void
    {
        $this->reset([
            'cinema_id', 'readOnly', 'slug', 'name', 'concept', 'address',
            'phone', 'business_hours', 'facility_info', 'access_note', 'map_embed_url',
        ]);
        $this->resetErrorBag();
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    /**
     * @return Collection<int, Cinema>
     */
    private function visibleCinemas(): Collection
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('viewAny', Cinema::class);

        return Cinema::visibleTo($admin)->orderBy('id')->get();
    }

    public function render(): View
    {
        return view('admin.cinemas.index', [
            'cinemas' => $this->visibleCinemas(),
        ])->layout('layouts.admin', ['title' => __('admin.cinema.title')]);
    }
}
