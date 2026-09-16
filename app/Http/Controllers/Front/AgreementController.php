<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Screening;
use App\Services\CurrentCinemaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 同意画面（P-32、7.7 / 4.3.7）。同意の操作は Livewire コンポーネント
 * （`Front\Reservation\Agreement`）が担い、本コントローラはメタ情報と共通レイアウトを
 * 組み立てるページを返す（13.4.3。P-31 と同じ分担）。
 *
 * URL に `{slug}` を持たないため `ResolveCinema` を通らない。ヘッダー・パンくずの館が
 * 前回選択したものにならないよう、上映回から定まる館を現在の館として確定させる
 *（`CurrentCinemaService::remember()`、4.3.9）。
 */
class AgreementController extends Controller
{
    public function __invoke(Request $request, int $id, CurrentCinemaService $cinemas): View
    {
        // preventLazyLoading（本番以外で有効）のため、ビューとコンポーネントが触れる
        // 関連はここで読み込む。
        $screening = Screening::with(['booking.movie', 'booking.format', 'booking.cinema', 'theater'])
            ->findOrFail($id);

        return view('front.reservation.agreement', [
            'screening' => $screening,
            'cinema' => $cinemas->remember($request, $screening->booking->cinema),
        ]);
    }
}
