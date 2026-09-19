<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * 予約照会（P-07、4.3.5 / 7.19）。照合の操作は Livewire コンポーネント
 * （`Front\Lookup\Index`）が担い、本クラスはページの器のみを組み立てる
 * （`IdentifyController` と同じ分担。13.4.3）。
 *
 * **館非依存ページ（P-05〜P-20）のため `{slug}` を持たず、館の解決も行わない。**
 * ヘッダー（`x-front.header`）が `CurrentCinemaService` から自前で解決するため、
 * 本文で館名を出す `front.placeholder`（`PagePlaceholderController`）と異なり、
 * ビューへ渡す必要が無い。**照合が済んだ後も現在の館を差し替えない**（4.3.17）。
 */
class LookupController extends Controller
{
    public function __invoke(): View
    {
        return view('front.lookup.index');
    }
}
