<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\CurrentCinemaService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;

/**
 * 館非依存ページ（7.1.1 P-01, P-05〜P-20）の工程2向け暫定コントローラ。
 * `PlaceholderController`（館別ページ用）と異なり `{slug}` を持たないため、
 * `CurrentCinemaService` でヘッダー表示用の館を解決する。
 * 各画面の実装（該当フェーズ、11.1）で画面ごとの内容へ差し替え、本クラスを削除する。
 */
class PagePlaceholderController extends Controller
{
    public function __invoke(Request $request, CurrentCinemaService $resolver): View
    {
        $screenId = $request->route('screenId');

        /** defaults() の付け忘れを検出する。実装者のミスであり、利用者に起因する 500 ではない。 */
        if (! is_string($screenId) || $screenId === '') {
            throw new LogicException('defaults(\'screenId\', ...) が設定されていません。');
        }

        return view('front.placeholder', [
            'cinema' => $resolver->resolve($request),
            'screenId' => $screenId,
        ]);
    }
}
