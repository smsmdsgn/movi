<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cinema_id
 * @property int $number
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cinema_id', 'number', 'name'])]
class Theater extends Model
{
    protected $table = 'm_theaters';

    /**
     * @return BelongsTo<Cinema, $this>
     */
    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class, 'cinema_id');
    }

    /**
     * @return HasMany<Seat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class, 'theater_id');
    }

    /**
     * @return HasMany<Screening, $this>
     */
    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class, 'theater_id');
    }

    /**
     * @return BelongsToMany<Format, $this>
     */
    public function formats(): BelongsToMany
    {
        return $this->belongsToMany(Format::class, 'm_theater_format', 'theater_id', 'format_id')->withTimestamps();
    }
}
