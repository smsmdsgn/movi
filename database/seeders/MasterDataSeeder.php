<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Format;
use App\Models\PostCategory;
use App\Models\SeatType;
use App\Models\TicketType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 上映規格・座席種別・券種・お知らせカテゴリーの各マスタ、および初期の
 * super-admin アカウントを投入する（docs/design.md 6.3.2 / 6.5.1 / 6.6 / 4.7.1 / 4.8.4-6）。
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

        foreach (SeedConfig::POST_CATEGORIES as $slug => $name) {
            PostCategory::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        if (! Admin::where('login_id', SeedConfig::SUPER_ADMIN_LOGIN_ID)->exists()) {
            $password = config('services.seed.super_admin_password');

            if (! is_string($password) || trim($password) === '') {
                $password = Str::password(16);
                $loginId = SeedConfig::SUPER_ADMIN_LOGIN_ID;
                $this->command->warn(
                    "SEED_SUPER_ADMIN_PASSWORD が未設定のため、super-admin（{$loginId}）のパスワードをランダム生成しました: {$password}"
                );
            }

            Admin::create([
                'login_id' => SeedConfig::SUPER_ADMIN_LOGIN_ID,
                'password' => $password,
                'name' => SeedConfig::SUPER_ADMIN_NAME,
                'role' => AdminRole::SuperAdmin,
                'cinema_id' => null,
                'is_active' => true,
            ]);
        }
    }
}
