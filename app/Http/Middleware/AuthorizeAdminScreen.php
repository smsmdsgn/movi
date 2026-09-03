<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面の各ルートへの到達可否を `view-admin-screen` Gate（AppServiceProvider）
 * で判定する。role による分岐は Gate に集約し、本クラスでは比較しない（13.4.2）。
 */
class AuthorizeAdminScreen
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        abort_unless(
            is_string($routeName) && Gate::forUser($request->user('admin'))->allows('view-admin-screen', $routeName),
            403
        );

        return $next($request);
    }
}
