<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\TurnstileService;
use Illuminate\View\View;

/**
 * お問い合わせフォーム（P-14、4.9.2）。ダミー実装であり、入力の検証と送信完了画面への
 * 遷移のみ行う（メール送信・DB保存は行わない）。入力の受付は Livewire コンポーネント
 * （`Front\Contact\Index`）が担い、本クラスはページの器のみを組み立てる
 * （`LookupController` と同じ分担。13.4.3）。
 *
 * **館非依存ページ（P-05〜P-20）のため `{slug}` を持たず、館の解決も行わない。**
 * ヘッダー（`x-front.header`）が `CurrentCinemaService` から自前で解決する。
 *
 * Turnstile のキーが揃っていない場合は、ウィジェットを描かないため `api.js` も
 * 読み込ませない（4.9.8「キー未設定時」）。
 */
class ContactController extends Controller
{
    public function __invoke(TurnstileService $turnstile): View
    {
        return view('front.contact.index', [
            'isTurnstileEnabled' => $turnstile->isConfigured(),
        ]);
    }
}
