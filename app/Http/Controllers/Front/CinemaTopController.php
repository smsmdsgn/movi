<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use App\Services\ScheduleService;
use Illuminate\View\View;

/**
 * 館トップ（P-21、7.3）。作品一覧タブと上映スケジュール表を表示する。
 *
 * 構成要素のうちメインバナー・カルーセル・小バナー・重要なお知らせ・お知らせ／キャンペーン・
 * 各種バナーリンク（7.3-2・4〜7・9）は工程7（お知らせ・バナー、11.1）で追加する。
 *
 * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。
 */
class CinemaTopController extends Controller
{
    public function __invoke(Cinema $cinema, ScheduleService $schedule): View
    {
        return view('front.cinema.show', [
            'cinema' => $cinema,
            'listings' => $schedule->movieListings($cinema),
        ]);
    }
}
