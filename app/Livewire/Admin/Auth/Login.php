<?php

namespace App\Livewire\Admin\Auth;

use App\Models\Admin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * 管理者ログイン（A-01）。17.1.2 の要件（顧客と分離したガード・レート制限・
 * 無効化された管理者の拒否）を満たす。メールを起点とする認証導線は持たない。
 */
class Login extends Component
{
    public string $login_id = '';

    public string $password = '';

    public function login(): void
    {
        $this->ensureIsNotRateLimited();

        $this->validate([
            'login_id' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('admin')->attempt([
            'login_id' => $this->login_id,
            'password' => $this->password,
            'is_active' => true,
        ])) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey());

            throw ValidationException::withMessages([
                'login_id' => __('admin.auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->ipThrottleKey());
        session()->regenerate();

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $this->redirect(route($admin->landingRouteName()), navigate: false);
    }

    /**
     * ログインIDとIPの組み合わせ、およびIP単独の双方を確認する。IP単独の
     * 制限が無いと、同一IPからログインIDを変えながらの総当たりを防げない
     * （17.1.1-3の顧客側「同一IPおよび同一メールアドレス」と同様の考え方）。
     */
    private function ensureIsNotRateLimited(): void
    {
        foreach ([$this->throttleKey(), $this->ipThrottleKey()] as $key) {
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw ValidationException::withMessages([
                    'login_id' => __('admin.auth.throttled', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ]);
            }
        }
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->login_id)).'|'.request()->ip();
    }

    private function ipThrottleKey(): string
    {
        return 'admin-login|'.request()->ip();
    }

    public function render(): View
    {
        return view('admin.auth.login')
            ->layout('layouts.admin-auth', ['title' => __('admin.auth.title')]);
    }
}
