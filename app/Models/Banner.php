<?php

namespace App\Models;

use App\Enums\BannerPosition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property BannerPosition $position
 * @property string $image_path
 * @property string|null $link_url
 * @property string $alt
 * @property int $sort_order
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $cinema_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['position', 'image_path', 'link_url', 'alt', 'sort_order', 'starts_at', 'ends_at', 'cinema_id'])]
class Banner extends Model
{
    protected $table = 'c_banners';

    protected function casts(): array
    {
        return [
            'position' => BannerPosition::class,
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * 4.7.2 の対象館（`cinema_id IS NULL` が全館共通）を絞り込む。
     * 条件と意図は `Post::forCinema()`（4.7.1 の抽出条件）と同一であり、
     * 顧客側（P-21 の各バナー。工程7-d）と管理画面（A-13）が共有する。
     * `$cinemaId` が null の場合は絞り込まない（全館横断）。
     *
     * @param  Builder<Banner>  $query
     * @return Builder<Banner>
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
     * 掲載期間内のバナーに絞る（4.7.2-2）。境界は両端を含む（4.7.5追記表）。
     *
     * **`isVisibleAt()` と同じ条件をSQLで表したもの。** 片方だけを変更しないこと。
     *
     * @param  Builder<Banner>  $query
     * @return Builder<Banner>
     */
    #[Scope]
    protected function visibleAt(Builder $query, CarbonImmutable $at): Builder
    {
        /** バインド時にも秒で切り捨てられるが、`isVisibleAt()` と同じ値であることを明示する。 */
        $at = $at->startOfSecond();

        return $query
            ->where(fn (Builder $scoped) => $scoped
                ->whereNull($this->qualifyColumn('starts_at'))
                ->orWhere($this->qualifyColumn('starts_at'), '<=', $at))
            ->where(fn (Builder $scoped) => $scoped
                ->whereNull($this->qualifyColumn('ends_at'))
                ->orWhere($this->qualifyColumn('ends_at'), '>=', $at));
    }

    /**
     * 掲載期間内か（4.7.2-2「公開期間外は非表示。期間未指定の場合は常時掲載」）。
     *
     * **`visibleAt()`（クエリスコープ）と同じ条件。** 判定が2系統になるため、
     * 片方だけを変更しないこと（4.7.5追記表）。
     */
    public function isVisibleAt(CarbonImmutable $at): bool
    {
        /** SQL へのバインドは秒単位に切り捨てられるため、`visibleAt()` と揃えて秒で比較する。 */
        $at = $at->startOfSecond();

        if ($this->starts_at !== null && $this->starts_at->greaterThan($at)) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->greaterThanOrEqualTo($at);
    }

    /**
     * バナー画像が実在するか。`BannerSeeder` は素材が未用意のためダミーのパスを
     * 投入しており（9.3追記表）、実在しない画像はプレースホルダーで代替する
     * （4.7.5追記表）。
     */
    public function hasImage(): bool
    {
        // 空文字はディスクのルートを指し、`exists()` が真になる（列は NOT NULL だが、
        // 空文字が入った場合に壊れた `<img>` を出さないため明示的に弾く）。
        if ($this->image_path === '') {
            return false;
        }

        return Storage::disk('public')->exists($this->image_path);
    }

    /**
     * 公開URL。`storage/app/public` を `php artisan storage:link` で公開する
     * 前提（4.7.3）。実在しない場合は null を返し、呼び出し側が
     * プレースホルダーを描く。絶対パスは書かない（3.5）。
     */
    public function imageUrl(): ?string
    {
        return $this->hasImage() ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * 顧客側の `href` に出してよいリンク先。`http` / `https` の絶対URLか、`/` で始まる
     * 自サイトのパスに限り、それ以外は null（リンクにしない）を返す。
     *
     * A-13 の検証（`url:http,https`、17.5.2-4）を通らない経路（シーダー・DBの直接操作）で
     * 入った値も、出力の時点で弾く。`//host` と `/\host` はブラウザが別ホストとして
     * 解釈するため自サイトのパスとして扱わない（`PostBodyService` と同じ判定）。
     */
    public function safeLinkUrl(): ?string
    {
        $url = $this->link_url;

        if ($url === null) {
            return null;
        }

        if (preg_match('#\Ahttps?://[^\s]+\z#i', $url) === 1 || preg_match('#\A/(?![/\\\\])[^\s]*\z#', $url) === 1) {
            return $url;
        }

        return null;
    }

    /**
     * @return BelongsTo<Cinema, $this>
     */
    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class, 'cinema_id');
    }
}
