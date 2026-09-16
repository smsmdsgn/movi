<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Screening;
use App\Services\CurrentCinemaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 予約フロー（P-31〜P-37）のページを返すコントローラの共通部分（4.3.11）。
 *
 * 各画面の操作は Livewire コンポーネントが担い、本クラスの派生はメタ情報と共通レイアウトを
 * 組み立てるページだけを返す（13.4.3）。3画面で同一の実装になった時点で寄せた
 *（4.3.10「予約フローのコントローラの重複」で P-34 追加時に判断すると決めていたもの）。
 *
 * 予約フローのURLは `{slug}` を持たず `ResolveCinema` を通らない。ヘッダー・パンくずの館が
 * 前回選択したものにならないよう、上映回から定まる館を現在の館として確定させる
 *（`CurrentCinemaService::remember()`、4.3.9）。
 */
abstract class ReservationStepController extends Controller
{
    /**
     * 返すビュー名。
     *
     * @return view-string
     */
    abstract protected function view(): string;

    /**
     * ビューとコンポーネントが触れる関連。preventLazyLoading（本番以外で有効）のため
     * ここで読み込む。券種・料金を要する画面（P-35 以降）は上書きする。
     *
     * @return array<int, string>
     */
    protected function relations(): array
    {
        return ['booking.movie', 'booking.format', 'booking.cinema', 'theater'];
    }

    public function __invoke(Request $request, int $id, CurrentCinemaService $cinemas): View
    {
        $screening = Screening::with($this->relations())->findOrFail($id);

        return view($this->view(), [
            'screening' => $screening,
            'cinema' => $cinemas->remember($request, $screening->booking->cinema),
        ]);
    }
}
