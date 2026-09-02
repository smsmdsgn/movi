<?php

namespace App\View\Components\Front;

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

        $this->switchOptions = Cinema::orderBy('id')->get()
            ->map(fn (Cinema $cinema) => [
                'cinema' => $cinema,
                'url' => $this->switchUrl($request, $cinema),
            ])
            ->all();
    }

    /**
     * 劇場切替時の遷移先URLを算出する。
     *
     * `{slug}` を持つルート（館別ページ）では、現在のルート名とURI由来の
     * パラメータを保ったまま `slug` だけを差し替える（4.1.3-4 / design.md 4.1.3追記表382行目）。
     * `{slug}` を持たないページ（館非依存ページ）には「同種のページ」が存在しないため、
     * 切替先の館トップへ遷移する（design.md 4.1.3追記表、本タスクで追記）。
     */
    private function switchUrl(Request $request, Cinema $target): string
    {
        $route = $request->route();
        $routeName = $route?->getName();

        if ($route === null || $routeName === null || ! in_array('slug', $route->parameterNames(), true)) {
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
