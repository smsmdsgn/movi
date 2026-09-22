<?php

namespace App\Livewire\Front\MyPage;

use App\Livewire\Front\Concerns\CancelsReservation;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * マイページの予約詳細（P-06、7.14）。会員専用。1画面1コンポーネント（13.4.3）。
 *
 * **到達の根拠は所有者の一致である**（17.2.1-1 / `ReservationPolicy::viewOwn()`）。
 * P-07 が照合の結果（`matchedIds`）を根拠とするのに対し、こちらは**毎回ログイン中の
 * 会員で絞り直す**。4.3.17 が P-07 について記した「他人の画面のHTMLを入手できれば
 * 再生しうる」性質が、この画面には無い（スナップショットを再生しても、そのセッションの
 * 会員が所有していなければ予約を読めない）。
 *
 * **館非依存ページ（P-05〜P-20）であり `{slug}` を持たない。** ヘッダーの館は直前に
 * 選択したものが出る（P-05・P-07 と同じ扱い。4.5.3 / 4.3.17）。予約を開いただけで
 * 利用者の館の選択を書き換えない。
 */
class ReservationDetail extends Component
{
    /**
     * キャンセルの導線（4.4 / 7.19-8）。**予約照会（P-07）と共有する。**
     * 4.4「操作導線」が会員の導線をこの画面と定めている。
     */
    use CancelsReservation;

    /**
     * 表示する予約のID。
     *
     * `#[Locked]` とするのは差し替えを防ぐためだが、**根拠はこれではない。**
     * 読み出しのたびにログイン中の会員で絞るため、仮に差し替えられても他人の予約は
     * 返らない（`ownedReservations()`）。
     */
    #[Locked]
    public int $reservationId;

    public function mount(int $reservationId): void
    {
        $this->reservationId = $reservationId;
    }

    public function render(): View
    {
        $reservation = $this->cancellableReservation();

        // 所有者でなくなる経路は無い（予約は削除しない）が、握りつぶさずに 404 とする。
        // コントローラと同じく、存在しない予約と他人の予約を区別しない（17.2.1-2）。
        if ($reservation === null) {
            abort(404);
        }

        return view('front.mypage.reservation-detail', [
            'reservation' => $reservation,
            ...$this->cancelViewData($reservation),
        ]);
    }

    /**
     * キャンセルの対象としてよい予約（`CancelsReservation`）。明細の描画にも使う。
     *
     * **所有関係を関連（`User::reservations()`）に委ねる**（`where('user_id', …)` を
     * 画面側に書き直さない。P-05 と同じ扱い。4.5.3 / 17.15 T-11）。
     */
    protected function cancellableReservation(): ?Reservation
    {
        return $this->ownedReservations()
            ?->with([
                'seats.seat',
                'seats.ticketType',
                'screening.theater',
                'screening.booking.movie',
                'screening.booking.format',
                'screening.booking.cinema',
            ])
            ->find($this->reservationId);
    }

    /** 確認を出してよい予約のID（`CancelsReservation`）。明細は読み込まない。 */
    protected function cancellableReservationId(): ?int
    {
        /** @var int|null $id */
        $id = $this->ownedReservations()?->whereKey($this->reservationId)->value('id');

        return $id;
    }

    /**
     * ログイン中の会員が所有する予約。**対象は `paid` と `cancelled` に限る**
     * （`Reservation::visibleToCustomer()`。P-05 の一覧・コントローラの判定と同じ条件を
     * モデルから引く。4.3.8「条件の集約」/ 4.5.3）。
     *
     * **ログイン状態を前提に置かない。** ルートは `auth` の配下にあり、Livewire は
     * `Authenticate` を永続ミドルウェアとして `/livewire/update` にも適用するが、
     * `Auth` の戻り値は型で確かめる（`Confirm` / `Identify` と揃える）。会員でなければ
     * `null` を返し、呼び出し側は「該当なし」として扱う（`render()` が 404 を返す）。
     *
     * @return Builder<Reservation>|null
     */
    private function ownedReservations(): ?Builder
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        // 所有関係は関連に委ねる。`getQuery()` は関連が付けた外部キーの条件を保ったまま
        // クエリビルダを返す。
        return $user->reservations()->getQuery()->visibleToCustomer();
    }
}
