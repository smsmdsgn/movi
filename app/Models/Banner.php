<?php

namespace App\Models;

use App\Enums\BannerPosition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property BannerPosition $position
 * @property string $image_path
 * @property string|null $link_url
 * @property string $alt
 * @property int $sort_order
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $cinema_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
     * @return BelongsTo<Cinema, $this>
     */
    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class, 'cinema_id');
    }
}
