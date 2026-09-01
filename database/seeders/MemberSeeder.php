<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * `users` の総数が `SeedConfig::MEMBER_COUNT` 名に達するまで会員を投入する
 * （docs/design.md 9.2「会員」）。スターターキット標準の test@example.com は
 * `DatabaseSeeder` が本シーダーより前に作成するため、`MEMBER_COUNT` 名のうち
 * 1名として数えられる（ReservationSeeder の会員プールへ含まれるようにするため。
 * 9.3追記表参照）。
 * 冪等性は件数で判定する。
 */
class MemberSeeder extends Seeder
{
    public function run(): void
    {
        $shortage = SeedConfig::MEMBER_COUNT - User::query()->count();

        if ($shortage <= 0) {
            return;
        }

        User::factory()->count($shortage)->create();
    }
}
