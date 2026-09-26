<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use Illuminate\View\View;

/**
 * アクセス（P-28、7.1.1 / 4.9.1）。館別ページはテンプレートを1枚のみ用意し、
 * 館マスタの `address`・`access_note`・地図（`map_embed_url`）を差し込む。
 *
 * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。再取得しない。
 */
class AccessController extends Controller
{
    public function __invoke(Cinema $cinema): View
    {
        return view('front.access.show', [
            'cinema' => $cinema,
        ]);
    }
}
