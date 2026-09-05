<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;

/**
 * 管理画面（7.1.1 A-10〜A-16）の工程3-a向け暫定コントローラ。
 * 各画面の実装（該当フェーズ、11.1）で画面ごとの内容へ差し替え、本クラスを削除する
 * （12章 残課題）。
 */
class PagePlaceholderController extends Controller
{
    public function __invoke(Request $request): View
    {
        $screenId = $request->route('screenId');

        /** defaults() の付け忘れを検出する。実装者のミスであり、利用者に起因する 500 ではない。 */
        if (! is_string($screenId) || $screenId === '') {
            throw new LogicException('defaults(\'screenId\', ...) が設定されていません。');
        }

        return view('admin.placeholder', [
            'screenId' => $screenId,
        ]);
    }
}
