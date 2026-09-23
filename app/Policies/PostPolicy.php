<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Post;

/**
 * お知らせ（A-12）の閲覧・投稿・編集可否を判定する（4.8.2 / 4.7.1）。
 *
 * `c_posts.cinema_id` は「全館共通（NULL）」と「特定館」を兼ねる館のタグであり
 * （4.7.1）、`CinemaScope`（13.4.1）を適用すると全館共通の記事が
 * `cinema-admin` から消える。そのため一覧の範囲は `Post::forCinema()`
 * （4.7.1 の抽出条件）が担い、**投稿・編集できる対象館の判定をこのPolicyに置く**
 * （4.7.4追記表「A-12 の館の範囲」）。
 *
 * 削除のアビリティは設けない（6.2「お知らせ・バナーは保持」。4.7.4追記表）。
 */
class PostPolicy
{
    /**
     * お知らせ画面自体の閲覧可否（4.8.2: `gate`ロールは権限を持たない）。
     * `AuthorizeAdminScreen`ミドルウェアはフルページロードのみを保護し
     * `/livewire/update`経由のアクション呼び出しには適用されないため（4.8.6追記表）、
     * 一覧取得・各アクションのたびにこのアビリティで判定する。
     */
    public function viewAny(Admin $admin): bool
    {
        return $this->canPost($admin);
    }

    /**
     * 新規投稿の可否（4.8.2: お知らせは `super-admin`・`cinema-admin` の双方が投稿する）。
     * 対象館の可否は `assignCinema` が判定する。
     */
    public function create(Admin $admin): bool
    {
        return $this->canPost($admin);
    }

    /**
     * 既存の記事の編集可否。対象館の判定は `assignCinema` と同一とし、
     * `cinema-admin` は全館共通の記事（`cinema_id` が NULL）を編集できない
     *（一覧には自館の画面に出る記事として表示されるが、本部の投稿である）。
     */
    public function update(Admin $admin, Post $post): bool
    {
        return $this->assignCinema($admin, $post->cinema_id);
    }

    /**
     * `create()` と同一の判定基準を、対象（`Post`インスタンス）を持たない画面要素
     *（新規投稿ボタン・投稿モーダルの表示可否）向けにクラスレベルのアビリティとして
     * 公開する（A-04・A-06・A-08・A-09と同じ理由）。
     */
    public function updateAny(Admin $admin): bool
    {
        return $this->canPost($admin);
    }

    /**
     * 対象館（`c_posts.cinema_id`）を指定して投稿・編集できるか（4.8.2
     *「お知らせ: `super-admin` は全館共通＋任意の館、`cinema-admin` は自館向けのみ」）。
     *
     * `Gate::forUser($admin)->allows('assignCinema', [Post::class, $cinemaId])` の形で
     * クラスレベルのアビリティとして呼ぶ。画面側が役割を比較せずに
     * 「全館共通を選べるか」「他館向けに投稿できないか」を判定できるようにする（13.4.2）。
     */
    public function assignCinema(Admin $admin, ?int $cinemaId): bool
    {
        if (! $this->canPost($admin)) {
            return false;
        }

        if ($admin->role === AdminRole::SuperAdmin) {
            return true;
        }

        return $cinemaId !== null && $cinemaId === $admin->cinema_id;
    }

    private function canPost(Admin $admin): bool
    {
        return $admin->role !== AdminRole::Gate;
    }
}
