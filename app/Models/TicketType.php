<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $price
 * @property int $display_order
 * @property string|null $condition
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'price', 'display_order', 'condition'])]
class TicketType extends Model
{
    /**
     * ペア割（6.5.2）の対象となる券種の名称。
     *
     * 名称で引けるのは、A-07 が券種名の変更を実装していないため（4.8.6追記表。
     * 6.5.1 が券種を固定集合として列挙し、`name` に一意制約がある）。
     * `m_ticket_types` にコード列は無く、名称が唯一の識別子になる。
     */
    public const string ADULT_NAME = '大人';

    protected $table = 'm_ticket_types';

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ReservationSeat, $this>
     */
    public function reservationSeats(): HasMany
    {
        return $this->hasMany(ReservationSeat::class, 'ticket_type_id');
    }
}
