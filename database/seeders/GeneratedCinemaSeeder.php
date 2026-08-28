<?php

namespace Database\Seeders;

use App\Models\Cinema;
use App\Models\Format;
use App\Models\Theater;
use Illuminate\Database\Seeder;

/**
 * 祇園ムビ以外の6館を SeedConfig の固定構成から生成する（docs/design.md 6.3.3 / 9.1）。
 */
class GeneratedCinemaSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SeedConfig::GENERATED_CINEMAS as $slug => $cinemaConfig) {
            $cinema = Cinema::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $cinemaConfig['name'],
                    'concept' => $cinemaConfig['concept'],
                    'address' => $cinemaConfig['address'],
                    'phone' => SeedConfig::CINEMA_PHONE,
                    'business_hours' => SeedConfig::BUSINESS_HOURS,
                    'facility_info' => '売店、コインロッカー、多目的トイレを備える。'.$cinemaConfig['station'].'から徒歩圏内。',
                    'access_note' => $cinemaConfig['station'].'から徒歩約5分。専用駐車場はなし。',
                    'map_embed_url' => 'https://www.google.com/maps?q='.rawurlencode($cinemaConfig['address']).'&output=embed',
                ]
            );

            foreach ($cinemaConfig['theaters'] as $index => $scale) {
                $number = $index + 1;
                $dimensions = SeedConfig::THEATER_SCALES[$scale];
                $hasAisle = in_array($scale, SeedConfig::SCALES_WITH_AISLE, true);
                $hasExecutive = in_array($scale, SeedConfig::SCALES_WITH_EXECUTIVE, true);
                $extraFormats = SeedConfig::THEATER_EXTRA_FORMATS[$slug][$number] ?? [];

                $theater = Theater::firstOrCreate(
                    ['cinema_id' => $cinema->id, 'number' => $number],
                    ['name' => "{$number}番シアター"]
                );

                $formatIds = Format::whereIn('name', array_merge(['2D'], $extraFormats))->pluck('id');
                $theater->formats()->syncWithoutDetaching($formatIds);

                SeatLayoutGenerator::generate($theater, $dimensions['rows'], $dimensions['cols'], $hasAisle, $hasExecutive);
            }
        }
    }
}
