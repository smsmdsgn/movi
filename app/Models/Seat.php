<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $theater_id
 * @property int $seat_type_id
 * @property string $row_label
 * @property string $seat_number
 * @property int $grid_row
 * @property int $grid_col
 * @property bool $is_available
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['theater_id', 'seat_type_id', 'row_label', 'seat_number', 'grid_row', 'grid_col', 'is_available'])]
class Seat extends Model
{
    protected $table = 'm_seats';

    protected function casts(): array
    {
        return [
            'grid_row' => 'integer',
            'grid_col' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Seat>  $query
     * @return Builder<Seat>
     */
    #[Scope]
    protected function available(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /**
     * @return BelongsTo<Theater, $this>
     */
    public function theater(): BelongsTo
    {
        return $this->belongsTo(Theater::class, 'theater_id');
    }

    /**
     * @return BelongsTo<SeatType, $this>
     */
    public function seatType(): BelongsTo
    {
        return $this->belongsTo(SeatType::class, 'seat_type_id');
    }

    public function displayName(): string
    {
        return $this->row_label.$this->seat_number;
    }
}
