<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $theater_id
 * @property int $seat_type_id
 * @property string $row_label
 * @property string $seat_number
 * @property int $grid_row
 * @property int $grid_col
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['theater_id', 'seat_type_id', 'row_label', 'seat_number', 'grid_row', 'grid_col'])]
class Seat extends Model
{
    protected $table = 'm_seats';

    protected function casts(): array
    {
        return [
            'grid_row' => 'integer',
            'grid_col' => 'integer',
        ];
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
