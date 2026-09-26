<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use Illuminate\View\View;

/**
 * 施設案内（P-27、7.1.1 / 4.9.1）。館別ページはテンプレートを1枚のみ用意し、
 * 館マスタの `concept`・`facility_info`・`business_hours`・`phone` を差し込む。
 *
 * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。再取得しない。
 */
class EstablishmentController extends Controller
{
    public function __invoke(Cinema $cinema): View
    {
        return view('front.establishment.show', [
            'cinema' => $cinema,
        ]);
    }
}
