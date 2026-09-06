<?php

namespace App\View\Components\Front;

use App\Models\Booking;
use App\Models\Cinema;
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

        $movieCinemaIds = $this->movieCinemaIds($request);

        $this->switchOptions = Cinema::orderBy('id')->get()
            ->map(fn (Cinema $cinema) => [
                'cinema' => $cinema,
                'url' => $this->switchUrl($request, $cinema, $movieCinemaIds),
            ])
            ->all();
    }

    /**
     * 作品詳細（P-23）では、表示中の作品に上映編成を持つ館のIDを1クエリで求めておく。
     * 持たない館へ切り替えた場合は同種のページが存在しない（`MovieController` が404を返す）ため、
     * 館トップへ落とす（4.1.3追記表「P-23 の館切替」）。P-23 以外では `null`。
     *
     * @return array<int, int>|null
     */
    private function movieCinemaIds(Request $request): ?array
    {
        $route = $request->route();

        if ($route === null || $route->getName() !== 'front.movie.show') {
            return null;
        }

        $movieId = $route->parameter('id');

        /** ルート制約 `whereNumber('id')` により通常は成立する。型を確定させるためのガード。 */
        if (! is_numeric($movieId)) {
            return [];
        }

        return Booking::query()
            ->where('movie_id', (int) $movieId)
            ->distinct()
            ->pluck('cinema_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * 劇場切替時の遷移先URLを算出する。
     *
     * `{slug}` を持つルート（館別ページ）では、現在のルート名とURI由来の
     * パラメータを保ったまま `slug` だけを差し替える（4.1.3-4 / design.md 4.1.3追記表382行目）。
     * `{slug}` を持たないページ（館非依存ページ）には「同種のページ」が存在しないため、
     * 切替先の館トップへ遷移する（design.md 4.1.3追記表）。
     * 作品詳細（P-23）で切替先の館が当該作品を上映していない場合も同様に館トップへ遷移する。
     *
     * @param  array<int, int>|null  $movieCinemaIds  P-23 のとき、作品を上映する館のID
     */
    private function switchUrl(Request $request, Cinema $target, ?array $movieCinemaIds): string
    {
        $route = $request->route();
        $routeName = $route?->getName();

        if ($route === null || $routeName === null || ! in_array('slug', $route->parameterNames(), true)) {
            return route('front.cinema.show', ['slug' => $target->slug]);
        }

        if ($movieCinemaIds !== null && ! in_array($target->id, $movieCinemaIds, true)) {
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
