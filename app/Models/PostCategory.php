<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['slug', 'name'])]
class PostCategory extends Model
{
    /** 「お知らせ」の slug（4.7.1）。 */
    public const SLUG_NOTICE = 'notice';

    /** 「キャンペーン」の slug（4.7.1）。 */
    public const SLUG_CAMPAIGN = 'campaign';

    /** 「重要なお知らせ」の slug（4.7.1）。顧客側で強調して表示する（7.3-6）。 */
    public const SLUG_IMPORTANT = 'important';

    protected $table = 'm_post_categories';

    public function isImportant(): bool
    {
        return $this->slug === self::SLUG_IMPORTANT;
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'category_id');
    }
}
