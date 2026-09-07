<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Screening;
use App\Services\CurrentCinemaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 座席選択（P-31、7.6）。座席表そのものは Livewire コンポーネント
 * （`Front\Reservation\SeatSelection`）が描画し、本コントローラはメタ情報と
 * 共通レイアウトを組み立てるページを返す（13.4.3 / 4.2.3追記表と同じ分担）。
 *
 * URL に `{slug}` を持たないため `ResolveCinema` を通らない。ヘッダー・パンくずの
 * 館が前回選択したものにならないよう、上映回から定まる館を現在の館として確定させる
 *（`CurrentCinemaService::remember()`、4.3.9）。
 */
class SeatSelectionController extends Controller
{
    public function __invoke(Request $request, int $id, CurrentCinemaService $cinemas): View
    {
        // preventLazyLoading（本番以外で有効）のため、ビューとコンポーネントが触れる
        // 関連はここで読み込む。
        $screening = Screening::with(['booking.movie', 'booking.format', 'booking.cinema', 'theater'])
            ->findOrFail($id);

        return view('front.reservation.seats', [
            'screening' => $screening,
            'cinema' => $cinemas->remember($request, $screening->booking->cinema),
        ]);
    }
}
