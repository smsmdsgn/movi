<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * お問い合わせ送信完了（P-15、4.9.2）。フォームはダミー実装であり、送信内容は
 * 保存しないため、本画面が持つ根拠は直前の送信で立てたセッションのフラグのみである。
 *
 * **フラグが無ければ入力フォーム（P-14）へ戻す。** 送信を経ずに直接URLを開いた場合に、
 * ダミーであっても「送信できた」かのような画面を見せない。
 *
 * **館非依存ページ（P-05〜P-20）のため `{slug}` を持たず、館の解決も行わない。**
 */
class ContactCompleteController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('contact.submitted') !== true) {
            return redirect()->route('front.contact.index');
        }

        return view('front.contact.complete');
    }
}
