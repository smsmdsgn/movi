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
 * @property string $concept
 * @property string $address
 * @property string $phone
 * @property string $business_hours
 * @property string $facility_info
 * @property string $access_note
 * @property string $map_embed_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['slug', 'name', 'concept', 'address', 'phone', 'business_hours', 'facility_info', 'access_note', 'map_embed_url'])]
class Cinema extends Model
{
    protected $table = 'm_cinemas';

    /**
     * @return HasMany<Theater, $this>
     */
    public function theaters(): HasMany
    {
        return $this->hasMany(Theater::class, 'cinema_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'cinema_id');
    }

    /**
     * @return HasMany<Admin, $this>
     */
    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class, 'cinema_id');
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'cinema_id');
    }

    /**
     * @return HasMany<Banner, $this>
     */
    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class, 'cinema_id');
    }
}
