<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ReservationSeeder が会員プールとして参照するため、MemberSeeder より前に作成する
        // （そうしないと test@example.com に予約・スタンプが一切紐づかない）。
        if (! User::where('email', SeedConfig::TEST_MEMBER_EMAIL)->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => SeedConfig::TEST_MEMBER_EMAIL,
            ]);
        }

        $this->call([
            MasterDataSeeder::class,
            GionSeeder::class,
            GeneratedCinemaSeeder::class,
            MovieSeeder::class,
            ScreeningSeeder::class,
            MemberSeeder::class,
            ReservationSeeder::class,
            GionReservationSeeder::class,
            PostSeeder::class,
            BannerSeeder::class,
        ]);
    }
}
