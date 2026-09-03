<?php

use App\Http\Middleware\ResolveCinema;
use App\Models\Admin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'cinema' => ResolveCinema::class,
        ]);

        // 顧客用 web ガードと管理用 admin ガードでリダイレクト先の名前空間が異なるため、
        // Laravel既定（ガードに関わらず route('login')/route('dashboard') 固定）を上書きする。
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));

        $middleware->redirectUsersTo(function (Request $request): string {
            if (! $request->is('admin', 'admin/*')) {
                return route('dashboard');
            }

            // ダッシュボードへ到達できないロール（gate）がログイン済みで
            // /admin/login を再訪問した際、到達可能な画面へ振り分ける（17.1.3）。
            // このクロージャは admin ガードで認証済みの場合のみ呼ばれる想定
            // （routes/admin.php の /admin/login は必ず guest:admin を使うこと。
            // ガード指定なしの guest を使うと web ガードの認証状態と衝突しループしうる）。
            $admin = Auth::guard('admin')->user();

            return $admin instanceof Admin ? route($admin->landingRouteName()) : route('admin.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
