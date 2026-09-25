<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use App\Models\Post;
use App\Models\PostCategory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * お知らせ一覧（P-24）・カテゴリー別（P-25、7.1.1 / 4.7.1）。同一のビューを共用する。
 *
 * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。`{category}` は
 * ルート定義上の位置引数のため、`PlaceholderController` と同様に `$request->route()` で
 * 取り出す（P-25 のみ値を持つ。P-24 のときは null）。
 */
class NewsController extends Controller
{
    public function __invoke(Request $request, Cinema $cinema): View
    {
        $categorySlug = $request->route('category');
        $category = $categorySlug === null
            ? null
            : PostCategory::query()->where('slug', $categorySlug)->firstOrFail();

        $posts = Post::query()
            ->published()
            ->forCinema($cinema->id)
            ->when($category !== null, fn ($query) => $query->where('category_id', $category->id))
            ->with('category')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(25);

        /** 範囲外のページ（`?page=999`）は空の一覧を200で返さず404とする。存在しないページを正規URLにしないため。 */
        abort_if($posts->isEmpty() && $posts->currentPage() > 1, 404);

        return view('front.news.index', [
            'cinema' => $cinema,
            'category' => $category,
            'categories' => PostCategory::orderBy('id')->get(),
            'posts' => $posts,
        ]);
    }
}
