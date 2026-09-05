<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 無効化された管理者（`m_admins.is_active = false`）のセッションを打ち切る（17.1.2-6）。
 *
 * `Auth::guard('admin')->attempt()` の資格情報に含めた `is_active` は**ログイン時にしか
 * 評価されない**（`EloquentUserProvider::retrieveById()` は主キーのみで取得する）。
 * A-14 で無効化が実運用の操作になったため、この確認が無いと無効化された管理者が
 * ログアウトするまで全権限を保持し続ける。
 *
 * `Livewire::addPersistentMiddleware()`（AppServiceProvider）に登録し、
 * フルページロードと `/livewire/update` の双方で評価する。
 */
class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin instanceof Admin && ! $admin->is_active) {
            Auth::guard('admin')->logout();

            // 4.8.6追記表「管理者ログアウトの実装」と同じ理由により、
            // `Session::invalidate()` ではなくIDの再生成に留める
            // （`admin`ガードと`web`ガードがCookieを共有するため）。
            $request->session()->regenerate();

            throw new AuthenticationException(guards: ['admin']);
        }

        return $next($request);
    }
}
