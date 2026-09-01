<?php

namespace App\Http\Middleware;

use App\Models\Cinema;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * URL の {slug} から館を解決し、コンテナへバインドする（13.4.1）。
 * 以降のルート・コンポーネントは Cinema を型宣言で受け取り、館を再取得しない。
 *
 * 本ミドルウェアを通らない文脈（Livewire の更新エンドポイント、Job、コマンド）では
 * バインドが存在せず `app(Cinema::class)` が空のモデルを返すため、
 * それらへは館を引数で引き渡す（4.1.3 実装で確定した事項）。
 */
class ResolveCinema
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');

        /** ルート定義上 {slug} は必須のため通常は成立する。型を確定させるためのガード。 */
        abort_unless(is_string($slug), 404);

        app()->instance(Cinema::class, Cinema::where('slug', $slug)->firstOrFail());

        return $next($request);
    }
}
