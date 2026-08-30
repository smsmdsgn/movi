<?php

namespace Database\Seeders;

use App\Enums\BannerPosition;
use App\Models\Banner;
use App\Models\Cinema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * バナー（c_banners）を投入する（docs/design.md 4.7.2）。掲載位置ごとの件数は
 * `SeedConfig::BANNER_COUNTS`（想定枚数の範囲内）に従う。実際の画像アセットは
 * 用意できないため、`image_path` はダミーのパスで投入する（9.1のTMDBポスター画像と
 * 同様の扱い。実装時にプレースホルダー画像へ差し替えること）。
 * 冪等性は掲載位置単位で判定する。
 */
class BannerSeeder extends Seeder
{
    public function run(): void
    {
        $cinemas = Cinema::orderBy('id')->get(['id', 'name', 'slug']);
        $now = CarbonImmutable::now();
        $banners = [];

        foreach (SeedConfig::BANNER_COUNTS as $position => $count) {
            if (Banner::where('position', $position)->exists()) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $cinema = $this->cinemaFor($cinemas, $position, $i);

                // 3枚以上投入する掲載位置は最後の1件を掲載期間切れとし、非表示動作を確認できるようにする
                // （4.7.2-2。9.3-5のお知らせと同じ考え方）。2枚（先頭=全館共通、残り1枚=館別）の位置では
                // 対象にしない。館別バナーが1枚も生きて残らなくなり、館スコープの表示確認ができなくなるため
                $isExpiredDemo = $count > 2 && $i === $count - 1;

                $banners[] = [
                    'position' => $position,
                    'image_path' => "banners/{$position}-{$i}.jpg",
                    'link_url' => $cinema !== null ? "/cinemas/{$cinema->slug}" : null,
                    'alt' => $cinema !== null ? "{$cinema->name}のバナー" : 'ムビ 全館共通バナー',
                    'sort_order' => $i + 1,
                    'starts_at' => $isExpiredDemo ? $now->subDays(30) : null,
                    'ends_at' => $isExpiredDemo ? $now->subDays(7) : null,
                    'cinema_id' => $cinema?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($banners !== []) {
            Banner::query()->insert($banners);
        }
    }

    /**
     * 先頭の1枚は必ず全館共通とし、残りを館ごとに割り当てる（1枚しかない `main` は常に全館共通）。
     * ハッシュの偏りに左右されず、全館共通と館別の両方が確実に生成される。
     *
     * @param  Collection<int, Cinema>  $cinemas
     */
    private function cinemaFor(Collection $cinemas, string $position, int $index): ?Cinema
    {
        if ($position === BannerPosition::Main->value || $cinemas->isEmpty() || $index === 0) {
            return null;
        }

        $indexSeed = crc32("{$position}-cinema-index-{$index}") & 0x7FFFFFFF;

        return $cinemas[$indexSeed % $cinemas->count()];
    }
}
