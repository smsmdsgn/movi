<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 工程2（館切替とルーティング）の検証用に、館の解決結果のみを返す暫定コントローラ。
 * 館別ページの実装（工程4以降）で画面ごとの Livewire コンポーネントへ差し替え、
 * 本クラスと `front/placeholder.blade.php` は削除する。
 */
class PlaceholderController extends Controller
{
    /**
     * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。
     * 画面ID（7.1.1）はルート定義の defaults() で与え、ルートパラメータとして受け取る
     * （コントローラの引数はルートパラメータを位置で受け取るため、名前では解決できない）。
     */
    public function __invoke(Request $request, Cinema $cinema): View
    {
        $screenId = $request->route('screenId');

        /** defaults() の付け忘れが空の画面IDで 200 を返さないようにする。 */
        abort_unless(is_string($screenId) && $screenId !== '', 500);

        return view('front.placeholder', [
            'cinema' => $cinema,
            'screenId' => $screenId,
        ]);
    }
}
