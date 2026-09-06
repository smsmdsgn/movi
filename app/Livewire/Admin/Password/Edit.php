<?php

namespace App\Livewire\Admin\Password;

use App\Models\Admin;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * パスワード変更（A-15）。ログイン中の管理者が自分自身のパスワードだけを変更する
 * （4.8.2「パスワード変更（自分自身）」）。他者のパスワードの再設定は `super-admin` が
 * A-14 から行う（4.8.4-5）ため、本画面は対象の選択を持たない。
 *
 * メールを起点とする再設定導線は持たない（4.8.4-4）ため、現在のパスワードの照合を
 * 唯一の本人確認とする。`current_password:admin` で `admin` ガードのユーザーと突き合わせる。
 *
 * 権限判定には `$this->authorize()` ではなく `Gate::forUser($admin)` を用いる。
 * `AuthorizesRequests::authorize()` は既定ガード（`web`）のユーザーを解決するため、
 * `admin` ガードでは常に未許可と判定される（13.4.2、AppServiceProviderと同じ理由）。
 */
class Edit extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(): void
    {
        $admin = $this->currentAdmin();

        Gate::forUser($admin)->authorize('updateOwnPassword', Admin::class);

        // 検証に失敗した場合も入力欄を空へ戻す。パスワード専用の画面であり、
        // Livewireのスナップショットに平文を残したままにしない。
        try {
            $data = $this->validate();
        } catch (ValidationException $e) {
            $this->reset(['current_password', 'password', 'password_confirmation']);

            throw $e;
        }

        // `password` は `hashed` キャストを持つため、平文を代入してモデル経由で保存する（5.5）。
        $admin->update(['password' => $data['password']]);

        $this->reset(['current_password', 'password', 'password_confirmation']);

        Flux::toast(text: __('admin.password.messages.saved'), variant: 'success');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            // `current_password` は Hasher で `admin` ガードの現在のユーザーと直接照合する。
            // 認証済みの本人による操作であるため、ログイン（17.1.2-4）のような
            // レート制限は課さない。
            'current_password' => ['required', 'string', 'current_password:admin'],
            // 17.1.2-3: パスワードは12文字以上。`Password::default()` は本番以外では
            // `min(8)` へフォールバックする（AppServiceProvider の既定が `null` を返すため）
            // ので、環境非依存の12文字を `Password::min(12)` で明示する。A-14 と同じ要件。
            'password' => [
                'required',
                'string',
                Password::min(12),
                Password::default(),
                'confirmed',
                'different:current_password',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = __('admin.password.fields');

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'password.different' => __('admin.password.errors.same_as_current'),
        ];
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    public function render(): View
    {
        return view('admin.password.edit')
            ->layout('layouts.admin', ['title' => __('admin.password.title')]);
    }
}
