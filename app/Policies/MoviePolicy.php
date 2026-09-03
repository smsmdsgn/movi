<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Movie;

/**
 * 映画マスタ（A-05）の閲覧・作成・編集可否を判定する（4.8.2: 作成・編集は
 * super-admin、cinema-admin は閲覧）。
 * `m_movies` は `cinema_id` を持たないチェーン共通のマスタ（6.1.2）であり
 * `CinemaScope`（13.4.1）の対象外のため、役割のみで判定する。
 */
class MoviePolicy
{
    /**
     * 映画マスタ画面自体の閲覧可否（4.8.2: `gate`ロールは権限を持たない）。
     * `AuthorizeAdminScreen`ミドルウェアはフルページロードのみを保護し
     * `/livewire/update`経由のアクション呼び出しには適用されないため（4.8.6追記表）、
     * 一覧取得・各アクションのたびにこのアビリティで判定する。
     */
    public function viewAny(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }

    /**
     * TMDBからの取り込み（＝新規登録）の可否（4.8.2 / 4.8.5）。
     * 作成はTMDBの取り込みを経由する経路のみで、手入力での登録は設けない。
     */
    public function create(Admin $admin): bool
    {
        return $this->canManageMovies($admin);
    }

    /**
     * 取り込み内容の手動上書き・追記、および対応する上映規格の設定の可否（4.8.5）。
     */
    public function update(Admin $admin, Movie $movie): bool
    {
        return $this->canManageMovies($admin);
    }

    /**
     * A-04・A-06 の `updateAny`（対象を持たない編集モーダルの表示可否）に相当する
     * アビリティは持たない。A-05 は A-03 と同じく閲覧と編集を同一のモーダルで扱い、
     * `cinema-admin` にも `$readOnly` でそのモーダルを開かせるため（4.8.2 の「閲覧」）、
     * モーダル自体を権限で隠す分岐が存在しないため。
     */
    private function canManageMovies(Admin $admin): bool
    {
        return $admin->role === AdminRole::SuperAdmin;
    }
}
