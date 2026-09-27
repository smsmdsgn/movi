<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\CurrentCinemaService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;

/**
 * 館非依存ページ（7.1.1）の工程2向け暫定コントローラ。
 * 館別ページと異なり `{slug}` を持たないため、`CurrentCinemaService` で
 * ヘッダー表示用の館を解決する。
 *
 * P-08〜P-13・P-16〜P-20 は工程7-fで専用の実装（コントローラまたは
 * `Route::view()`）へ差し替え済み。**現時点で残るのは P-14・P-15（お問い合わせ、
 * 未実装）のみ。** その他の画面が実装される際は、本クラスを削除する。
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
