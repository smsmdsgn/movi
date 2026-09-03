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
 * `admin` ガードのセッションが残っていれば、顧客側ページ（front）を同一ブラウザで
 * 開いた場合にも適用される（4.8.6 追記表）。
 *
 * @implements Scope<Model>
 */
class CinemaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
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
