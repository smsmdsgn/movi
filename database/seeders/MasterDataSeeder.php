<?php

namespace Database\Seeders;

use App\Models\Format;
use App\Models\SeatType;
use App\Models\TicketType;
use Illuminate\Database\Seeder;

/**
 * 上映規格・座席種別・券種の各マスタを投入する（docs/design.md 6.3.2 / 6.5.1 / 6.6）。
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SeedConfig::FORMATS as $name => $defaultSurcharge) {
            Format::firstOrCreate(['name' => $name], ['default_surcharge' => $defaultSurcharge]);
        }

        foreach (SeedConfig::SEAT_TYPES as $name => $attributes) {
            SeatType::firstOrCreate(['name' => $name], $attributes);
        }

        foreach (SeedConfig::TICKET_TYPES as $displayOrder => $ticketType) {
            TicketType::firstOrCreate(
                ['name' => $ticketType['name']],
                [
                    'price' => $ticketType['price'],
                    'display_order' => $displayOrder + 1,
                    'condition' => $ticketType['condition'],
                ]
            );
        }
    }
}
