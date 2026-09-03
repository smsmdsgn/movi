<?php

namespace App\Livewire\Admin\Formats;

use App\Models\Admin;
use App\Models\Format;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 上映規格マスタ（A-06）。super-admin が既定追加料金を編集する（4.8.2）。
 * 6.6 が規格を5件の固定集合として列挙しているため、新規作成・削除に加えて
 * 規格名（`name`）の変更も対象外とし、一覧と `default_surcharge` の編集のみを
 * 実装する（4.8.6追記表）。`m_formats` は `cinema_id` を持たないチェーン共通の
 * マスタであり `CinemaScope`（13.4.1）の対象外のため、館セレクタは設けない。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる
 * （13.4.2、A-03・A-04と同じ理由）。
 */
class Index extends Component
{
    /**
     * `wire:model.self` でモーダルと二方向バインドするため `#[Locked]` にできない
     * （A-03・A-04と同じ）。更新対象は `editingFormatId`（`#[Locked]`）が特定する。
     */
    public bool $showEditForm = false;

    #[Locked]
    public ?int $editingFormatId = null;

    /** 編集対象を示すための表示専用の値。入力欄ではないため `#[Locked]` とする。 */
    #[Locked]
    public string $name = '';

    public string $default_surcharge = '';

    public function editFormat(int $formatId): void
    {
        $admin = $this->currentAdmin();
        $format = Format::findOrFail($formatId);

        Gate::forUser($admin)->authorize('update', $format);

        $this->editingFormatId = $format->id;
        $this->name = $format->name;
        $this->default_surcharge = (string) $format->default_surcharge;
        $this->resetErrorBag();
        $this->showEditForm = true;
    }

    public function saveFormat(): void
    {
        if ($this->editingFormatId === null) {
            // editFormat() を経ずに呼ばれた場合（例: /livewire/updateへの不正な直接呼び出し）。
            return;
        }

        $admin = $this->currentAdmin();
        $format = Format::findOrFail($this->editingFormatId);

        Gate::forUser($admin)->authorize('update', $format);

        $data = $this->validate();

        $format->update($data);

        $this->resetEditForm();
        Flux::toast(text: __('admin.format.messages.saved'), variant: 'success');
    }

    public function cancelEditFormat(): void
    {
        $this->resetEditForm();
    }

    private function resetEditForm(): void
    {
        $this->reset(['editingFormatId', 'name', 'default_surcharge']);
        $this->resetErrorBag();
        $this->showEditForm = false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'default_surcharge' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.format.fields');

        return $attributes;
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    /**
     * @return Collection<int, Format>
     */
    private function visibleFormats(): Collection
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('viewAny', Format::class);

        return Format::orderBy('id')->get();
    }

    public function render(): View
    {
        return view('admin.formats.index', [
            'formats' => $this->visibleFormats(),
        ])->layout('layouts.admin', ['title' => __('admin.format.title')]);
    }
}
