<?php

namespace App\Livewire\Front\Reservation;

use App\Livewire\Front\Reservation\Concerns\ResolvesScreening;
use App\Livewire\Front\Reservation\Concerns\UsesReservationDraft;
use App\Models\Screening;
use App\Models\Seat;
use App\Services\SeatLockService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 同意画面（P-32、7.7 / 4.3.7）。1画面1コンポーネント（13.4.3）。
 *
 * 表示する座席（4.3.7-5）は P-31 と同じく `t_seat_locks` を読み直して求める（6.4.3-2）。
 * 保持している座席が無ければ、同意を求めずに座席選択（P-31）へ戻す導線を出す。
 *
 * **ポーリングしない**（4.3.10）。座席表を持たず、他のお客様の操作で変わる表示が無い。
 * ロックの期限切れは「次へ進む」の時点で読み直して検出する。
 *
 * 得た同意は `ReservationDraft`（セッション）へ記録する。本画面を経ずに P-33 以降の
 * URLへ直接到達した利用者を、後続の画面が判別できるようにするため（4.3.12）。
 */
class Agreement extends Component
{
    use ResolvesScreening;
    use UsesReservationDraft;

    /**
     * 表示中の案内の文言キー（7.17）。言語ファイルのキーをクライアントから
     * 差し替えられないよう `Locked` とする（P-31 と同じ扱い）。
     */
    #[Locked]
    public ?string $messageKey = null;

    /** 利用規約への同意（4.3.7-7。未チェックでは次へ進めない）。 */
    public bool $agreed = false;

    public function mount(Screening $screening): void
    {
        $this->rememberScreening($screening);
    }

    /**
     * 次へ進む（会員／非会員の選択 P-33、7.8）。
     *
     * 判定順は 販売期間 → 座席の保持 → 同意 とする。座席を失っている利用者に同意を促しても、
     * 次の行動（座席の選び直し）につながらない（7.17 のトーン）。
     *
     * **販売不可・座席0件の案内は本メソッドでは設定しない。** いずれも描画時点の状態であり、
     * `render()` の `noticeKey()` が同じ判定で文言を決める（4.3.10）。ここで代入しても
     * 必ず上書きされるため、進めないことだけを決める。
     *
     * 同意は `ReservationDraft` に記録する。これが無ければ P-33 以降は先へ進めない
     *（4.3.12。旧12章 残課題25）。
     */
    public function proceed(SeatLockService $locks): void
    {
        $this->messageKey = null;

        $screening = $this->screeningOnSale();

        if ($screening === null) {
            return;
        }

        if ($locks->heldSeatIds($screening, $locks->holderKey()) === []) {
            return;
        }

        if (! $this->agreed) {
            $this->messageKey = 'front.reservation.errors.not_agreed';

            return;
        }

        $this->draft()->agree($screening);

        $this->redirect(route('front.reservation.identify', ['id' => $this->screeningId]), navigate: false);
    }

    public function render(SeatLockService $locks): View
    {
        $screening = $this->screeningOnSale();
        $seats = $screening !== null ? $this->heldSeats($screening, $locks) : new EloquentCollection;

        return view('front.reservation.agreement-form', [
            'onSale' => $screening !== null,
            'seats' => $seats,
            // 3つの分岐の文言を1つのライブリージョンへ流す（要素ごと出し入れすると
            // 読み上げられない実装がある。P-31 のビューと同じ扱い）。
            'noticeKey' => $this->noticeKey($screening !== null, $seats->isNotEmpty(), $locks),
            'maxSeats' => SeatLockService::MAX_SEATS_PER_HOLDER,
            'seatsUrl' => route('front.reservation.seats', ['id' => $this->screeningId]),
            'termsUrl' => route('front.terms.index'),
        ]);
    }

    /**
     * 画面に出す案内（7.17）。描画時点の状態が操作の結果より優先される
     * （座席を失った利用者に、直前の操作に対する「同意が必要です」を出さない）。
     */
    private function noticeKey(bool $onSale, bool $hasSeats, SeatLockService $locks): ?string
    {
        if (! $onSale) {
            return 'front.reservation.errors.out_of_sale';
        }

        if ($hasSeats) {
            return $this->messageKey;
        }

        return $this->lostSeatsNoticeKey($locks->holderKey());
    }

    /**
     * 保持中の座席（4.3.7-5）。座席表（P-31）と同じ並び順で表示する。
     *
     * @return EloquentCollection<int, Seat>
     */
    private function heldSeats(Screening $screening, SeatLockService $locks): EloquentCollection
    {
        $heldSeatIds = $locks->heldSeatIds($screening, $locks->holderKey());

        if ($heldSeatIds === []) {
            return new EloquentCollection;
        }

        return Seat::query()
            ->whereIn('id', $heldSeatIds)
            ->orderBy('grid_row')
            ->orderBy('grid_col')
            ->get();
    }
}
