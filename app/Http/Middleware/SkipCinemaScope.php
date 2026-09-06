<?php

namespace App\Http\Middleware;

use App\Models\Scopes\CinemaScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 顧客側（front）のルートで `CinemaScope`（13.4.1）を適用しないことを宣言する。
 *
 * `CinemaScope` は `admin` ガードのセッションの有無で発火するため、管理者が同一ブラウザで
 * 顧客側ページを開くと、他館の上映スケジュール等が絞り込まれて空になる
 * （4.2.3追記表「顧客側での `CinemaScope` の扱い」）。顧客側は `ResolveCinema` が
 * 解決した館で明示的に絞り込むため、グローバルスコープによる絞り込みは不要である。
 *
 * `Livewire::addPersistentMiddleware()`（AppServiceProvider）に登録し、顧客側の
 * Livewire コンポーネント（日付切替等）の `/livewire/update` でも同じ判定にする。
 * 管理画面（routes/admin.php）のルートには付けないため、管理画面側の挙動は変わらない。
 *
 * バインドは応答の生成後に解除する。`app()->instance()` はリクエスト境界で破棄されず、
 * 1つのテスト内で顧客側ページを叩いた後に管理画面の館スコープ（T-02）を検証すると
 * スコープが無言で無効化されるため（`CurrentCinemaService` の `Cinema` と同じ性質）。
 * ビューは `$next()` の内側（Response の生成時）で描画済みのため、解除後に参照されない。
 */
class SkipCinemaScope
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->instance(CinemaScope::SKIP_BINDING, true);

        try {
            return $next($request);
        } finally {
            app()->forgetInstance(CinemaScope::SKIP_BINDING);
        }
    }
}
