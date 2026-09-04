<?php

namespace App\Livewire\Admin\TicketTypes;

use App\Models\Admin;
use App\Models\TicketType;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 券種・料金マスタ（A-07）。super-admin が価格と適用条件を編集する（4.8.2）。
 * 6.5.1 が券種を5件の固定集合として列挙し `m_ticket_types.name` の一意制約も
 * その前提（6.1.2追記表）であるうえ、9章のシーダーが券種を名称で引く
 * （`SeedConfig::TICKET_TYPE_ADULT`、`TICKET_TYPE_WEIGHTS` のキー、
 * `ReservationSeeder`・`GionReservationSeeder`）ため、新規作成・削除に加えて
 * 券種名（`name`）・表示順（`display_order`）の変更も対象外とし、一覧と
 * `price`・`condition` の編集のみを実装する（4.8.6追記表）。`m_ticket_types` は
 * `cinema_id` を持たないチェーン共通のマスタであり（6.5「館ごとの料金差は設けず、
 * 全館共通とする」）`CinemaScope`（13.4.1）の対象外のため、館セレクタは設けない。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる
 * （13.4.2、A-06と同じ理由）。
 */
class Index extends Component
{
    /**
     * `wire:model.self` でモーダルと二方向バインドするため `#[Locked]` にできない
     * （A-06と同じ）。更新対象は `editingTicketTypeId`（`#[Locked]`）が特定する。
     */
    public bool $showEditForm = false;

    #[Locked]
    public ?int $editingTicketTypeId = null;

    /** 編集対象を示すための表示専用の値。入力欄ではないため `#[Locked]` とする。 */
    #[Locked]
    public string $name = '';

    public string $price = '';

    public string $condition = '';

    public function editTicketType(int $ticketTypeId): void
    {
        $admin = $this->currentAdmin();
        $ticketType = TicketType::findOrFail($ticketTypeId);

        Gate::forUser($admin)->authorize('update', $ticketType);

        $this->editingTicketTypeId = $ticketType->id;
        $this->name = $ticketType->name;
        $this->price = (string) $ticketType->price;
        $this->condition = (string) $ticketType->condition;
        $this->resetErrorBag();
        $this->showEditForm = true;
    }

    public function saveTicketType(): void
    {
        if ($this->editingTicketTypeId === null) {
            // editTicketType() を経ずに呼ばれた場合（例: /livewire/updateへの不正な直接呼び出し）。
            return;
        }

        $admin = $this->currentAdmin();
        $ticketType = TicketType::findOrFail($this->editingTicketTypeId);

        Gate::forUser($admin)->authorize('update', $ticketType);

        // 適用条件は表示用の案内文（6.5.1）であり、空欄と空白のみの入力を区別しない。
        // 検証より前に正規化するのは、`max:255` を除去後の長さに掛けるため。
        // 全角空白（U+3000）は `trim()` も `\s` も対象にしないため明示する（`u`修飾子必須）。
        $this->condition = (string) preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $this->condition);

        $data = $this->validate();
        $data['condition'] = $this->condition === '' ? null : $this->condition;

        $ticketType->update($data);

        $this->resetEditForm();
        Flux::toast(text: __('admin.ticket_type.messages.saved'), variant: 'success');
    }

    public function cancelEditTicketType(): void
    {
        $this->resetEditForm();
    }

    private function resetEditForm(): void
    {
        $this->reset(['editingTicketTypeId', 'name', 'price', 'condition']);
        $this->resetErrorBag();
        $this->showEditForm = false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'price' => ['required', 'integer', 'min:1', 'max:10000'],
            'condition' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.ticket_type.fields');

        return $attributes;
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    /**
     * @return Collection<int, TicketType>
     */
    private function visibleTicketTypes(): Collection
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('viewAny', TicketType::class);

        return TicketType::orderBy('display_order')->orderBy('id')->get();
    }

    public function render(): View
    {
        return view('admin.ticket-types.index', [
            'ticketTypes' => $this->visibleTicketTypes(),
        ])->layout('layouts.admin', ['title' => __('admin.ticket_type.title')]);
    }
}
