<?php

namespace App\View\Components\Front;

use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Post;
use App\Services\CurrentCinemaService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\Component;

/**
 * 共通レイアウトのヘッダー（7.2.1）。
 * ロゴ・劇場切替セレクトボックス・マイページリンクを表示する。
 */
class Header extends Component
{
    public Cinema $currentCinema;

    /** @var array<int, array{cinema: Cinema, url: string}> */
    public array $switchOptions;

    public function __construct(CurrentCinemaService $resolver, Request $request)
    {
        $this->currentCinema = $resolver->resolve($request);

        $detailCinemaIds = $this->detailCinemaIds($request);

        $this->switchOptions = Cinema::orderBy('id')->get()
            ->map(fn (Cinema $cinema) => [
                'cinema' => $cinema,
                'url' => $this->switchUrl($request, $cinema, $detailCinemaIds),
            ])
            ->all();
    }

    /**
     * 詳細ページ（P-23・P-26）では、表示中の作品・記事を切替先でも表示できる館のIDを
     * 1クエリで求めておく。表示できない館へ切り替えた場合は同種のページが404になるため、
     * 館トップへ落とす（4.1.3追記表「P-23・P-26 の館切替」）。制限が無い場合は `null`。
     *
     * - P-23: 作品に上映編成を持つ館（`MovieController` が404を返す条件と同じ）
     * - P-26: 全館共通の記事は制限なし、特定館の記事はその館のみ
     *   （`NewsDetailController` が404を返す条件と同じ）
     *
     * @return array<int, int>|null
     */
    private function detailCinemaIds(Request $request): ?array
    {
        $route = $request->route();
        $routeName = $route?->getName();

        if ($route === null || ! in_array($routeName, ['front.movie.show', 'front.news.show'], true)) {
            return null;
        }

        $id = $route->parameter('id');

        /** ルート制約 `whereNumber('id')` により通常は成立する。型を確定させるためのガード。 */
        if (! is_numeric($id)) {
            return [];
        }

        if ($routeName === 'front.news.show') {
            $post = Post::query()->published()->whereKey((int) $id)->first(['id', 'cinema_id']);

            if ($post === null) {
                return [];
            }

            return $post->cinema_id === null ? null : [(int) $post->cinema_id];
        }

        return Booking::query()
            ->where('movie_id', (int) $id)
            ->distinct()
            ->pluck('cinema_id')
            ->map(fn (int|string $cinemaId): int => (int) $cinemaId)
            ->all();
    }

    /**
     * 劇場切替時の遷移先URLを算出する。
     *
     * `{slug}` を持つルート（館別ページ）では、現在のルート名とURI由来の
     * パラメータを保ったまま `slug` だけを差し替える（4.1.3-4 / design.md 4.1.3追記表382行目）。
     * `{slug}` を持たないページ（館非依存ページ）には「同種のページ」が存在しないため、
     * 切替先の館トップへ遷移する（design.md 4.1.3追記表）。
     * 詳細ページ（P-23・P-26）で切替先の館に同じ作品・記事が無い場合も同様に館トップへ遷移する。
     *
     * @param  array<int, int>|null  $detailCinemaIds  詳細ページのとき、同じ作品・記事を表示できる館のID
     */
    private function switchUrl(Request $request, Cinema $target, ?array $detailCinemaIds): string
    {
        $route = $request->route();
        $routeName = $route?->getName();

        if ($route === null || $routeName === null || ! in_array('slug', $route->parameterNames(), true)) {
            return route('front.cinema.show', ['slug' => $target->slug]);
        }

        if ($detailCinemaIds !== null && ! in_array($target->id, $detailCinemaIds, true)) {
            return route('front.cinema.show', ['slug' => $target->slug]);
        }

        $uriParams = Arr::only($route->parameters(), $route->parameterNames());

        return route($routeName, [...$uriParams, 'slug' => $target->slug]);
    }

    public function render(): View|Closure|string
    {
        return view('components.front.header');
    }
}
