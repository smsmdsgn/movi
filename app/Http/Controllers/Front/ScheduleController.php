<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use Illuminate\View\View;

/**
 * 上映スケジュール（P-22、7.4）。館トップにも掲載する上映スケジュール表を単独ページで表示する。
 * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。
 */
class ScheduleController extends Controller
{
    public function __invoke(Cinema $cinema): View
    {
        return view('front.schedule.index', [
            'cinema' => $cinema,
        ]);
    }
}
