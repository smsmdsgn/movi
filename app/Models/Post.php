<?php

namespace App\Models;

use App\Enums\PostStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $category_id
 * @property int|null $cinema_id
 * @property int|null $created_by_admin_id
 * @property string $title
 * @property string $body
 * @property PostStatus $status
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['category_id', 'cinema_id', 'title', 'body', 'status', 'published_at'])]
class Post extends Model
{
    protected $table = 'c_posts';

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * 4.7.1 の抽出条件（`cinema_id IS NULL OR cinema_id = {対象館}`）を適用する。
     * 全館共通の記事（`cinema_id` が NULL）は、どの館を対象にしても残る。
     * `$cinemaId` が null の場合は絞り込まない（`super-admin` の全館横断）。
     *
     * `CinemaScope`（グローバルスコープ）を用いないのは、`cinema_id = ?` の
     * 単純な一致では全館共通の記事が落ちるためである（4.7.4追記表）。
     * 顧客側（P-21・P-24〜P-26）と管理画面（A-12）が同じ条件を共有する。
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    #[Scope]
    protected function forCinema(Builder $query, ?int $cinemaId): Builder
    {
        if ($cinemaId === null) {
            return $query;
        }

        return $query->where(fn (Builder $scoped) => $scoped
            ->whereNull($this->qualifyColumn('cinema_id'))
            ->orWhere($this->qualifyColumn('cinema_id'), $cinemaId));
    }

    /**
     * @return BelongsTo<PostCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Cinema, $this>
     */
    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class, 'cinema_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
