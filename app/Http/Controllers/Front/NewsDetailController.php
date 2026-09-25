<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use App\Models\Post;
use App\Services\PostBodyService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * お知らせ詳細（P-26、7.1.1 / 4.7.1）。下書き・公開日時が未来またはNULL・他館専用の記事・
 * 存在しないIDはすべて404とする（`Post::published()` / `Post::forCinema()`）。
 *
 * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。館切替時のフォールバック
 * （全館共通の記事は同じ記事詳細へ、特定館の記事は切替先の館トップへ）は
 * `App\View\Components\Front\Header` が担う。
 */
class NewsDetailController extends Controller
{
    public function __invoke(Request $request, Cinema $cinema, PostBodyService $postBody): View
    {
        $post = Post::query()
            ->published()
            ->forCinema($cinema->id)
            ->with('category')
            ->whereKey($request->route('id'))
            ->firstOrFail();

        $body = $postBody->render($post->body);

        return view('front.news.show', [
            'cinema' => $cinema,
            'post' => $post,
            'body' => $body,
            'excerpt' => $postBody->plainText($body),
        ]);
    }
}
