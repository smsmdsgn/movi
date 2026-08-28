<?php

namespace Database\Seeders;

use App\Models\Cinema;
use App\Models\Format;
use App\Models\Theater;
use Illuminate\Database\Seeder;

/**
 * 祇園ムビ（本館）を手動データとして投入する（docs/design.md 4.1.1 / 9.1）。
 * シアター構成は6.3.3「祇園ムビのシアター構成」に固定値として定義されている。
 */
class GionSeeder extends Seeder
{
    public function run(): void
    {
        $cinema = Cinema::firstOrCreate(
            ['slug' => SeedConfig::GION_SLUG],
            [
                'name' => SeedConfig::GION_NAME,
                'concept' => SeedConfig::GION_CONCEPT,
                'address' => SeedConfig::GION_ADDRESS,
                'phone' => SeedConfig::CINEMA_PHONE,
                'business_hours' => SeedConfig::BUSINESS_HOURS,
                'facility_info' => '売店、コインロッカー、多目的トイレを備える。'.SeedConfig::GION_STATION.'から徒歩圏内。',
                'access_note' => SeedConfig::GION_STATION.'から徒歩約5分。専用駐車場はなし。',
                'map_embed_url' => 'https://www.google.com/maps?q='.rawurlencode(SeedConfig::GION_ADDRESS).'&output=embed',
            ]
        );

        foreach (SeedConfig::GION_THEATERS as $number => $config) {
            $theater = Theater::firstOrCreate(
                ['cinema_id' => $cinema->id, 'number' => $number],
                ['name' => "{$number}番シアター"]
            );

            $formatNames = array_merge(['2D'], $config['formats']);
            $formatIds = Format::whereIn('name', $formatNames)->pluck('id');
            $theater->formats()->syncWithoutDetaching($formatIds);

            SeatLayoutGenerator::generate($theater, $config['rows'], $config['cols'], $config['hasAisle'], $config['hasExecutive']);
        }
    }
}
