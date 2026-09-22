<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * マイページの予約詳細（P-06、7.14）。会員専用（`auth` ミドルウェア）。
 *
 * **ページの器のみを組み立てる。** 明細の表示とキャンセルの操作は Livewire コンポーネント
 * （`Front\MyPage\ReservationDetail`）が担う（`LookupController` と同じ分担。13.4.3）。
 * キャンセルの成立によって明細（状態・注記・入場の案内）も書き換わるため、画面全体を
 * 1つのコンポーネントが描く。
 *
 * **URLのIDを変えて他人の予約へ到達できないことは Policy で担保する**（17.2.1-2）。
 * ここでの判定はフルページロードのためのものであり、`/livewire/update` 経由の操作は
 * コンポーネント側が所有者で絞り直す（4.8.6追記表が管理画面について述べているのと同じ
 * 理由で、ミドルウェアは Livewire のアクション呼び出しに適用されない）。
 *
 * **館非依存ページ（P-05〜P-20）のため館の解決を行わない。** ヘッダー（`x-front.header`）
 * が `CurrentCinemaService` から自前で解決する（P-05 と同じ扱い。4.5.3）。
 */
class MyPageReservationController extends Controller
{
    public function __invoke(int $id): View
    {
        // **対象は `paid` と `cancelled` に限る**（`Reservation::visibleToCustomer()`。
        // P-05 の一覧と同じ条件をモデルから引く。4.3.8「条件の集約」/ 4.5.3）。
        $reservation = Reservation::query()->visibleToCustomer()->find($id);

        // **存在しない予約と、他人の予約を区別しない**（17.2.1-2。403 では対象が在る
        // ことが分かる。P-38 と同じ方針。4.3.16）。
        if ($reservation === null || Gate::denies('viewOwn', $reservation)) {
            abort(404);
        }

        return view('front.mypage.reservation', ['reservationId' => $reservation->id]);
    }
}
