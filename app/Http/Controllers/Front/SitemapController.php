<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use Illuminate\View\View;

/**
 * サイトマップ（P-20、7.1.1 / 4.9.1）。
 *
 * 館非依存ページ（P-01・P-07〜P-14・P-16〜P-19。送信完了の P-15 は除く）と、館ごと（P-21・P-22・P-24・P-27・P-28）のリンクを一覧する。
 * マイページ（会員専用）と予約フロー（上映回IDに依存し時間の経過とともに存在しなくなる。
 * 19.3-6）は載せない。
 *
 * 館はビューで扱わない。ヘッダーが `CurrentCinemaService` で自前に解決する（P-07 と同じ）。
 */
class SitemapController extends Controller
{
    public function __invoke(): View
    {
        return view('front.pages.sitemap', [
            'cinemas' => Cinema::orderBy('id')->get(),
        ]);
    }
}
