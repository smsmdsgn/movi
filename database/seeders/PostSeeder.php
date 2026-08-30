<?php

namespace Database\Seeders;

use App\Enums\PostStatus;
use App\Models\Admin;
use App\Models\Cinema;
use App\Models\Post;
use App\Models\PostCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * お知らせ（c_posts）を投入する（docs/design.md 9.1 / 9.2 / 4.7.1）。
 * 9.1は祇園ムビのお知らせを「手動」とするが、`ScreeningSeeder` と同じ理由
 * （汎用的な内容で足りるため個別に作り込むコストに見合わない。9.3追記表参照）で、
 * 祇園ムビも含めた全7館に同一のシーダーを適用する。
 * カテゴリーごとに `SeedConfig::POST_COUNT_PER_CATEGORY` 件（計300件）を生成し、
 * `published_at` を過去数年に分散させる（9.3-4）。あわせて `draft` の記事と
 * `published_at` が未来の記事を一部含め、非表示動作を確認できるようにする（9.3-5）。
 * 冪等性はカテゴリー単位で判定する。
 */
class PostSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = Admin::where('login_id', SeedConfig::SUPER_ADMIN_LOGIN_ID)->firstOrFail()->id;
        $cinemaIds = Cinema::orderBy('id')->pluck('id')->all();
        $now = CarbonImmutable::now();

        foreach (SeedConfig::POST_CATEGORIES as $slug => $name) {
            $category = PostCategory::where('slug', $slug)->firstOrFail();

            if (Post::where('category_id', $category->id)->exists()) {
                continue;
            }

            $posts = [];

            for ($i = 0; $i < SeedConfig::POST_COUNT_PER_CATEGORY; $i++) {
                $template = $this->templateFor($slug, $i);
                $cinemaId = $this->cinemaIdFor($cinemaIds, $slug, $i);

                [$status, $publishedAt] = $this->statusAndPublishedAtFor($i, $now);

                $posts[] = [
                    'category_id' => $category->id,
                    'cinema_id' => $cinemaId,
                    'created_by_admin_id' => $adminId,
                    'title' => $template['title'],
                    'body' => $template['body'],
                    'status' => $status->value,
                    'published_at' => $publishedAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            collect($posts)->chunk(500)->each(fn ($chunk) => Post::query()->insert($chunk->all()));
        }
    }

    /**
     * @return array{title: string, body: string}
     */
    private function templateFor(string $categorySlug, int $index): array
    {
        $templates = SeedConfig::POST_TEMPLATES[$categorySlug];

        return $templates[$index % count($templates)];
    }

    /**
     * @param  array<int, int>  $cinemaIds
     */
    private function cinemaIdFor(array $cinemaIds, string $categorySlug, int $index): ?int
    {
        if ($cinemaIds === []) {
            return null;
        }

        $allCinemasSeed = crc32("{$categorySlug}-all-cinemas-{$index}") & 0x7FFFFFFF;

        if ($allCinemasSeed % 3 === 0) {
            return null;
        }

        $indexSeed = crc32("{$categorySlug}-cinema-index-{$index}") & 0x7FFFFFFF;

        return $cinemaIds[$indexSeed % count($cinemaIds)];
    }

    /**
     * @return array{0: PostStatus, 1: CarbonImmutable|null}
     */
    private function statusAndPublishedAtFor(int $index, CarbonImmutable $now): array
    {
        $draftCount = SeedConfig::POST_DRAFT_COUNT_PER_CATEGORY;
        $futureCount = SeedConfig::POST_FUTURE_COUNT_PER_CATEGORY;
        $normalCount = SeedConfig::POST_COUNT_PER_CATEGORY - $draftCount - $futureCount;

        if ($index < $draftCount) {
            return [PostStatus::Draft, null];
        }

        if ($index < $draftCount + $futureCount) {
            $offset = $index - $draftCount;
            $daysAhead = 1 + intdiv($offset * SeedConfig::POST_FUTURE_SPAN_DAYS, $futureCount);

            return [PostStatus::Published, $now->addDays($daysAhead)];
        }

        $offset = $index - $draftCount - $futureCount;
        $spanDays = SeedConfig::POST_PUBLISHED_SPAN_YEARS * 365;
        $daysAgo = $spanDays - intdiv($offset * $spanDays, max(1, $normalCount));

        return [PostStatus::Published, $now->subDays($daysAgo)];
    }
}
