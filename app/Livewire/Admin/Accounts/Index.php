<?php

namespace App\Livewire\Admin\Accounts;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Cinema;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 管理者アカウント管理（A-14）。`super-admin` のみが到達し、管理者の発行・編集・
 * 無効化を行う（4.8.2 / 4.8.4）。メールを起点とする導線は持たず、パスワードは
 * `super-admin` がこの画面から直接設定する（4.8.4-4・5）。
 *
 * 物理削除は行わず `is_active` で運用する（4.8.4-7）。当該管理者が登録した
 * 上映回・お知らせとの関連を失わないため、削除の操作自体を設けない。
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
    public ?int $account_id = null;

    public string $login_id = '';

    public string $name = '';

    public string $role = '';

    public string $cinema_id = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function create(): void
    {
        Gate::forUser($this->currentAdmin())->authorize('create', Admin::class);

        $this->resetForm();
        $this->role = AdminRole::CinemaAdmin->value;
        $this->showForm = true;
    }

    public function edit(int $accountId): void
    {
        $account = Admin::findOrFail($accountId);

        Gate::forUser($this->currentAdmin())->authorize('update', $account);

        $this->account_id = $account->id;
        $this->login_id = $account->login_id;
        $this->name = $account->name;
        $this->role = $account->role->value;
        $this->cinema_id = (string) ($account->cinema_id ?? '');
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetErrorBag();

        $this->showForm = true;
    }

    /**
     * 役割を `super-admin` へ切り替えた際に、選択済みの所属館を解除する。
     * `super-admin` は全館を横断するため館に属さない（4.8.2 / 6.1 `m_admins`）。
     */
    public function updatedRole(): void
    {
        if (! $this->requiresCinema()) {
            $this->cinema_id = '';
        }
    }

    public function save(): void
    {
        $admin = $this->currentAdmin();

        if ($this->account_id === null) {
            Gate::forUser($admin)->authorize('create', Admin::class);

            $data = $this->validate();

            Admin::create([
                'login_id' => $data['login_id'],
                'password' => $data['password'],
                'name' => $data['name'],
                'role' => AdminRole::from($data['role']),
                'cinema_id' => $this->cinemaIdValue(),
                'is_active' => true,
            ]);
        } else {
            $account = Admin::findOrFail($this->account_id);

            Gate::forUser($admin)->authorize('update', $account);

            $data = $this->validate();

            $this->ensureRoleChangeIsAllowed($admin, $account);

            $attributes = [
                'login_id' => $data['login_id'],
                'name' => $data['name'],
                'role' => AdminRole::from($data['role']),
                'cinema_id' => $this->cinemaIdValue(),
            ];

            // 空欄はパスワードの据え置きを意味する（4.8.4-5 の再設定は任意の操作）。
            if ($this->isSettingPassword()) {
                $attributes['password'] = $this->password;
            }

            $account->update($attributes);
        }

        $this->showForm = false;
        Flux::toast(text: __('admin.account.messages.saved'), variant: 'success');
    }

    /**
     * 有効／無効を切り替える（4.8.4-7）。自分自身は `manageAccess` が拒否する。
     */
    public function toggleActive(int $accountId): void
    {
        $account = Admin::findOrFail($accountId);

        Gate::forUser($this->currentAdmin())->authorize('manageAccess', $account);

        $account->update(['is_active' => ! $account->is_active]);

        Flux::toast(
            text: __($account->is_active ? 'admin.account.messages.activated' : 'admin.account.messages.deactivated'),
            variant: 'success',
        );
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
            // 4.8.4-1: ログインIDは発行された文字列ID（メールアドレスを使用しない）。
            'login_id' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique(Admin::class, 'login_id')->ignore($this->account_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(AdminRole::class)],
            // 4.8.6追記表: 全館横断が許可されない役割で `cinema_id` が未設定だと
            // `CinemaScope` が403を送出する。登録時点で設定漏れを弾く。
            'cinema_id' => $this->requiresCinema()
                ? ['required', Rule::exists(Cinema::class, 'id')]
                : ['prohibited'],
            // 17.1.2-3: パスワードは12文字以上。`Password::default()` は本番以外では
            // `min(8)` へフォールバックする（AppServiceProvider の既定が `null` を返すため）
            // ので、環境非依存の12文字を `Password::min(12)` で明示する。併せて
            // `Password::default()` も課し、本番の追加要件（大小文字・数字・記号・
            // 漏洩チェック）を顧客側と同等以上に保つ（17.1.2 の【根拠】）。
            'password' => $this->isSettingPassword()
                ? ['required', 'string', Password::min(12), Password::default(), 'confirmed']
                : ['nullable'],
        ];
    }

    /**
     * パスワードを設定・変更しようとしているか（新規発行時は常に真）。
     *
     * **`trim()` で判定すること。** バリデータは空白のみの文字列を「空」とみなして
     * 非 implicit なルール（`Password::min()`・`confirmed`）を飛ばす
     * （`Validator::presentOrRuleIsImplicit()`）。保存側を厳密な空文字比較にすると、
     * `' '`（空白1文字）が全ルールを素通りしたうえで「変更あり」と判定され、
     * そのままパスワードとして保存される。
     */
    private function isSettingPassword(): bool
    {
        return $this->account_id === null || trim($this->password) !== '';
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.account.fields');

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'cinema_id.prohibited' => __('admin.account.errors.cinema_prohibited'),
        ];
    }

    /**
     * `super-admin` 以外は所属館を持つ（4.8.2: `cinema-admin`・`gate` は自館のみ）。
     */
    private function requiresCinema(): bool
    {
        return $this->role !== '' && $this->role !== AdminRole::SuperAdmin->value;
    }

    private function cinemaIdValue(): ?int
    {
        return $this->cinema_id === '' ? null : (int) $this->cinema_id;
    }

    /**
     * 自分自身の役割は変更できない（`manageAccess`）。降格すると A-14 へ到達できず、
     * メールを起点とする復旧導線も無いため（4.8.4-4）自らを締め出すことになる。
     * 公開プロパティはクライアントから改変され得るため、UIの制御だけに委ねない。
     */
    private function ensureRoleChangeIsAllowed(Admin $admin, Admin $account): void
    {
        if ($this->role === $account->role->value) {
            return;
        }

        if (Gate::forUser($admin)->allows('manageAccess', $account)) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => __('admin.account.errors.self_role_change'),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['account_id', 'login_id', 'name', 'role', 'cinema_id', 'password', 'password_confirmation']);
        $this->resetErrorBag();
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    /**
     * @return Collection<int, Admin>
     */
    private function accounts(): Collection
    {
        Gate::forUser($this->currentAdmin())->authorize('viewAny', Admin::class);

        return Admin::with('cinema')->orderBy('id')->get();
    }

    public function render(): View
    {
        return view('admin.accounts.index', [
            'accounts' => $this->accounts(),
            'cinemas' => Cinema::visibleTo($this->currentAdmin())->orderBy('id')->get(),
            'roles' => AdminRole::cases(),
        ])->layout('layouts.admin', ['title' => __('admin.account.title')]);
    }
}
