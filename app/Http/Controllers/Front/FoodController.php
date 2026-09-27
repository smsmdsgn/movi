<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use Illuminate\View\View;

/**
 * フード・ドリンクメニュー（P-09、7.1.1 / 4.9.1）。
 *
 * メニュー本文はダミーで `lang/ja/front.php` に固定文言として持つ（管理画面の編集対象と
 * しない 4.9.1、文言は言語ファイルに置く 20.1）。4.9.1 の「館別注記」は、取り扱い品目が
 * 館により異なる旨の注記と各館の施設案内（P-27）への導線で代える（4.9.5）。
 *
 * 館はビューで扱わない。ヘッダーが `CurrentCinemaService` で自前に解決する（P-07 と同じ）。
 */
class FoodController extends Controller
{
    public function __invoke(): View
    {
        return view('front.pages.food', [
            'cinemas' => Cinema::orderBy('id')->get(),
        ]);
    }
}
