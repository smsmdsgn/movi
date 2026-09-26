<?php

namespace App\Http\Controllers\Front;

use App\Enums\BannerPosition;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Cinema;
use App\Models\Post;
use App\Models\PostCategory;
use App\Services\ScheduleService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;

/**
 * 館トップ（P-21、7.3）。作品一覧タブ・上映スケジュール表・バナー・お知らせを表示する
 * （工程7-d、7.3-2・4〜7・9）。
 *
 * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。
 */
class CinemaTopController extends Controller
{
    /** 小バナーの掲載枚数（4.7.2「想定枚数 2」、7.3-5）。 */
    private const SUB_BANNER_LIMIT = 2;

    /** お知らせ／キャンペーン・重要なお知らせの区画に出す最新件数（7.3-6・7）。 */
    private const NEWS_LIMIT = 5;

    public function __invoke(Cinema $cinema, ScheduleService $schedule): View
    {
        $now = Date::now();

        $banners = Banner::query()
            ->forCinema($cinema->id)
            ->visibleAt($now)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /** @var Collection<string, PostCategory> $categories */
        $categories = PostCategory::query()
            ->whereIn('slug', [PostCategory::SLUG_IMPORTANT, PostCategory::SLUG_NOTICE, PostCategory::SLUG_CAMPAIGN])
            ->get()
            ->keyBy('slug');

        $importantSection = $this->newsSection($cinema->id, $categories->get(PostCategory::SLUG_IMPORTANT));

        return view('front.cinema.show', [
            'cinema' => $cinema,
            'listings' => $schedule->movieListings($cinema),
            'mainBanner' => $banners->firstWhere('position', BannerPosition::Main),
            'carouselBanners' => $banners->where('position', BannerPosition::Carousel)->values(),
            'subBanners' => $banners->where('position', BannerPosition::Sub)->take(self::SUB_BANNER_LIMIT)->values(),
            'footerBanners' => $banners->where('position', BannerPosition::FooterLink)->values(),
            // 7.3-6「該当記事がある場合のみ表示」。
            'importantSection' => $importantSection !== null && $importantSection['posts']->isNotEmpty() ? $importantSection : null,
            'newsSections' => collect([PostCategory::SLUG_NOTICE, PostCategory::SLUG_CAMPAIGN])
                ->map(fn (string $slug): ?array => $this->newsSection($cinema->id, $categories->get($slug)))
                ->filter()
                ->values(),
        ]);
    }

    /**
     * カテゴリー1件分の区画（7.3-6・7）を組み立てる。カテゴリーの行が無い場合は null を返し、
     * その区画自体を出さない。
     *
     * カテゴリーは記事のリレーション経由で読まず、ここで組にして渡す。ビューで
     * `$post->category` を読むと、2件以上のコレクションでは遅延読み込みの禁止
     * （`Model::preventLazyLoading()`、ローカル・テスト環境）に触れて例外になる。
     *
     * @return array{category: PostCategory, posts: EloquentCollection<int, Post>}|null
     */
    private function newsSection(int $cinemaId, ?PostCategory $category): ?array
    {
        if ($category === null) {
            return null;
        }

        return [
            'category' => $category,
            'posts' => $this->latestPosts($cinemaId, $category),
        ];
    }

    /**
     * 指定カテゴリーの最新記事（公開制御は 4.7.1、抽出範囲は 4.7.4追記表）。
     *
     * @return EloquentCollection<int, Post>
     */
    private function latestPosts(int $cinemaId, PostCategory $category): EloquentCollection
    {
        return Post::query()
            ->published()
            ->forCinema($cinemaId)
            ->where('category_id', $category->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::NEWS_LIMIT)
            ->get();
    }
}
