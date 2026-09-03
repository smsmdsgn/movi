<?php

namespace App\Providers;

use App\Enums\AdminRole;
use App\Models\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAdminScreenGate();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * 管理画面の各ルートへの到達可否（4.8.2 / 4.8.5 / 17.1.3）を一元管理する。
     * 個別の画面・ミドルウェアに役割比較を分散させない（13.4.2）。
     */
    private function configureAdminScreenGate(): void
    {
        Gate::define('view-admin-screen', function (Admin $admin, string $routeName): bool {
            if ($admin->role === AdminRole::Gate) {
                // 17.1.3: gate ロールは入場確認以外の操作を行えない。
                return $routeName === 'admin.gate.index';
            }

            if (in_array($routeName, ['admin.banner.index', 'admin.account.index'], true)) {
                // 4.8.2: バナー・管理者アカウント管理は super-admin 限定。
                return $admin->role === AdminRole::SuperAdmin;
            }

            return true;
        });
    }
}
