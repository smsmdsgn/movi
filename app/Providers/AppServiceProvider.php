<?php

namespace App\Providers;

use App\Enums\AdminRole;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\SkipCinemaScope;
use App\Models\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Stripe クライアント（8.2）。`StripeService` からのみ解決する。テストでは
        // 差し替えて実際の通信を行わない。シークレットキー（15.1）はここでのみ読み、
        // 画面・ログへ渡る経路を作らない（17.3-6 / 17.9-1）。
        $this->app->singleton(StripeClient::class, function (): StripeClient {
            $secret = config('services.stripe.secret');

            return new StripeClient(is_string($secret) ? trim($secret) : '');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->denyInactiveAdmins();
        $this->configureAdminScreenGate();

        // 無効化された管理者のセッション打ち切り（17.1.2-6）を `/livewire/update`
        // 経由のアクション呼び出しにも効かせる。Livewireは、元のリクエストの
        // ルートに付いていたミドルウェアのうちこのリストにあるものだけを再適用する
        // ため、管理画面（routes/admin.php）以外へは波及しない。
        Livewire::addPersistentMiddleware(EnsureAdminIsActive::class);

        // 顧客側ルートの「CinemaScope を適用しない」宣言（SkipCinemaScope）を、
        // 上映スケジュール表の日付切替等の `/livewire/update` にも引き継ぐ。
        // 元のリクエストが顧客側ルートだった場合にのみ再適用される。
        Livewire::addPersistentMiddleware(SkipCinemaScope::class);
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
     * 無効化された管理者（17.1.2-6）には、すべての Gate・Policy の判定で権限を与えない。
     *
     * セッションの打ち切りは `EnsureAdminIsActive` が担うが、権限の判定は同ミドルウェアの
     * 有無に依存させない。操作者の有効性を Policy ごとに確認すると、確認の有無が Policy
     * ごとにばらつき、追加した Policy で書き漏れる（4.8.6追記表「操作者の有効性の確認」）。
     * 顧客（`User`）とゲストは対象外とし、`null` を返して通常の判定へ委ねる。
     */
    private function denyInactiveAdmins(): void
    {
        Gate::before(function (mixed $user): ?bool {
            return $user instanceof Admin && ! $user->is_active ? false : null;
        });
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
