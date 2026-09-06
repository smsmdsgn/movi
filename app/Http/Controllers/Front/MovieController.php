<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use App\Models\Movie;
use App\Services\ScheduleService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 作品詳細（P-23、7.5）。選択中の館で上映編成（`t_bookings`）を持つ作品のみを表示し、
 * 持たない作品は404とする（4.1.3追記表「P-23 の館切替」）。上映終了後の作品も
 * 館トップの「上映終了」タブから到達するため、期間の新旧は問わない。
 *
 * $cinema は ResolveCinema がコンテナへバインドした館（13.4.1）。
 * `{id}` はコントローラの引数では受け取らない（ルートパラメータは `{slug}` を含めて
 * 位置で渡されるため、`PlaceholderController` と同様に `$request->route()` で取り出す）。
 */
class MovieController extends Controller
{
    public function __invoke(Request $request, Cinema $cinema, ScheduleService $schedule): View
    {
        $movie = Movie::query()->with('formats')->whereKey($request->route('id'))->firstOrFail();
        $bookings = $schedule->bookingsOf($cinema, $movie);

        abort_if($bookings->isEmpty(), 404);

        return view('front.movie.show', [
            'cinema' => $cinema,
            'movie' => $movie,
            'bookings' => $bookings,
        ]);
    }
}
