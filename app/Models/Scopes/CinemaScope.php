<?php

namespace App\Models\Scopes;

use App\Models\Admin;
use App\Models\Cinema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * `admin` ガードで認証中の管理者を、自館のデータのみに絞り込む（13.4.1）。
 * 全館横断の参照可否は `CinemaPolicy::viewAllCinemas` に判定を委ねる（13.4.2）。
 *
 * `admin` ガードのセッションが残っていても、顧客側（front）のルートでは適用しない。
 * 顧客側のルートは `SkipCinemaScope` ミドルウェアが `SKIP_BINDING` をコンテナへ
 * バインドして宣言する（4.2.3追記表「顧客側での `CinemaScope` の扱い」）。
 * コンソール・Job 等のルートを持たない文脈ではバインドが無く、従来どおり
 * `admin` ガードの認証状態のみで判定する。
 *
 * @implements Scope<Model>
 */
class CinemaScope implements Scope
{
    /**
     * 顧客側のリクエストであることを示すコンテナのキー。`SkipCinemaScope` が設定する。
     */
    public const string SKIP_BINDING = 'cinema-scope.skip';

    public function apply(Builder $builder, Model $model): void
    {
        if (app()->bound(self::SKIP_BINDING)) {
            return;
        }

        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin) {
            return;
        }

        if (Gate::forUser($admin)->allows('viewAllCinemas', Cinema::class)) {
            return;
        }

        if ($admin->cinema_id === null) {
            // 全館横断が許可されない役割で所属館が未設定の場合、絞り込み条件を
            // 適用できない。無言で0件を返すと設定漏れが「空の一覧」として
            // 現れ原因特定が困難になるため、実装ミスとして即座に検出する。
            throw new HttpException(403, '所属館が設定されていない管理者です。');
        }

        $builder->where($model->qualifyColumn('cinema_id'), $admin->cinema_id);
    }
}
