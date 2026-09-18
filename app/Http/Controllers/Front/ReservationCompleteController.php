<?php

namespace App\Http\Controllers\Front;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Services\CurrentCinemaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * 予約完了（P-38、7.13）。
 *
 * **操作を伴わないため Livewire を用いない**（13.4.3。予約フローの他の画面と異なり、
 * 確定済みの内容を示すだけである）。`ReservationStepController` の派生にもしない
 * （URLが上映回IDではなく予約番号であり、上映回は予約からたどる）。
 *
 * **予約番号だけでは到達できない**（17.2.1-1 / 12章 旧残課題34）。予約番号は8桁の数字で
 * あり推測できるため、所有者の判定を `ReservationPolicy::view()` に委ね、
 * 認められない場合は 404 を返す。
 */
class ReservationCompleteController extends Controller
{
    public function __invoke(Request $request, string $no, CurrentCinemaService $cinemas): View
    {
        $reservation = Reservation::query()
            ->with([
                // 予約者名は出さない（7.13 の表示項目に含まれない）ため `user` は読まない。
                // 所有者の判定は `user_id` の比較で足りる（`ReservationPolicy::view()`）。
                'seats.seat',
                'seats.ticketType',
                'screening.theater',
                'screening.booking.movie',
                'screening.booking.format',
                'screening.booking.cinema',
            ])
            ->where('reservation_no', $no)
            // **確定済みの予約に限る。** `pending` は課金の直前に作られる行であり
            // （4.3.15）、`expired` / `cancelled` は終端（4.3.3）。いずれも「ご予約が
            // 完了しました」を示す対象ではない。キャンセル済みの予約内容は予約照会
            // （P-07）が扱う。
            ->where('status', ReservationStatus::Paid)
            ->first();

        // **存在しない予約番号と、他人の予約番号を区別しない。** どちらも 404 とする
        // ことで、予約番号の総当たりから「その番号の予約が在る」ことすら得られない
        // （403 では在ることが分かる。17.2.2「失敗理由を詳細に返さない」と同じ方針）。
        if ($reservation === null || Gate::denies('view', $reservation)) {
            abort(404);
        }

        return view('front.reservation.complete', [
            'reservation' => $reservation,
            'screening' => $reservation->screening,
            // 座席表（P-31）・予約確認（P-37）と同じ並び順で示す。
            'seats' => $reservation->seats
                ->sortBy(fn (ReservationSeat $row): array => [$row->seat->grid_row, $row->seat->grid_col])
                ->values(),
            // 予約フローのURLは `{slug}` を持たず `ResolveCinema` を通らない。ヘッダー・
            // パンくずの館が前回選択したものにならないよう、予約から定まる館を現在の館
            // として確定させる（`ReservationStepController` と同じ理由。4.3.9）。
            'cinema' => $cinemas->remember($request, $reservation->screening->booking->cinema),
        ]);
    }
}
