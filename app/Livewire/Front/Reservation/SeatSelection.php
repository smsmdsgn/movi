<?php

namespace App\Livewire\Front\Reservation;

use App\Enums\SeatSelectionState;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Services\SeatLockService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 座席選択（P-31、7.6）。1画面1コンポーネント（13.4.3）。
 *
 * **選択状態は画面ではなく `t_seat_locks` が持つ**（6.4.3-2）。クリックのたびに
 * `SeatLockService` でロックを取得・解放し、描画はそのロックを読み直して行う。
 * コンポーネントのプロパティに選択中の座席IDを保持すると、他者の取得・期限切れ・
 * 別タブでの操作との間にずれが生じ、決済確定（8.2 手順1）で失敗する。
 *
 * 表示は10秒間隔のポーリングで更新する（6.4.3-1。WebSocket は使えない）。
 */
class SeatSelection extends Component
{
    #[Locked]
    public Screening $screening;

    /**
     * 表示中の案内の文言キー（7.17）。次の操作まで消さない（ポーリングを跨いで残る。
     * 一瞬で消える案内は読めない）。言語ファイルのキーをクライアントから差し替え
     * られないよう `Locked` とする。
     */
    #[Locked]
    public ?string $messageKey = null;

    /**
     * 直前の描画時点で保持していた座席数。ポーリング時の増減からロックの期限切れ
     * （6.4.1-3）を検出するためだけに持つ（7.17。選択中の座席そのものは保持しない）。
     */
    #[Locked]
    public int $heldSeatCount = 0;

    public function mount(Screening $screening, SeatLockService $locks): void
    {
        $this->screening = $screening;

        // 4.3.4「別の上映回の座席選択に移行した時点で、前の上映回のロックを解放する」。
        // `acquire()` は他の上映回のロックが残っていると取得を拒むため、入場時に解放する（13.4.6）。
        //
        // **販売期間外の回では解放しない。** 座席を1席も選べない画面（開始済みの回への
        // 古いリンク等）への到達は 4.3.4 の「座席選択に移行」に当たらず、解放すると
        // 別の回で選択中・決済中の座席が何の通知も無く失われる（4.3.9）。
        if ($screening->isOnSale()) {
            $locks->releaseOtherScreenings($screening, $locks->holderKey());
        }
    }

    /**
     * 座席のクリック（7.6.2）。保持中なら解除、そうでなければ取得を試みる。
     *
     * `acquire()` は失敗理由を返さない（4.3.8）。7.17 が別の文言を定める
     * 「販売期間外」と「座席数の上限」は本メソッドが取得の前に判定し、
     * 残る失敗（他者のロック・予約済み・座席の無効化）を「他のお客様が選択中」として扱う（4.3.9）。
     */
    public function toggle(int $seatId, SeatLockService $locks): void
    {
        $this->messageKey = null;

        if (! $this->screening->isOnSale()) {
            $this->messageKey = 'front.reservation.errors.out_of_sale';

            return;
        }

        $holderKey = $locks->holderKey();

        // 座席表に描画していない座席ID（改ざん、または A-04 による無効化の直後）。
        // 取得条件は `acquire()` が改めて判定するため、ここでは対象の特定のみを行う。
        $seat = Seat::query()
            ->available()
            ->where('theater_id', $this->screening->theater_id)
            ->find($seatId);

        if ($seat === null) {
            $this->messageKey = 'front.reservation.errors.lock_failed';

            return;
        }

        $heldSeatIds = $locks->heldSeatIds($this->screening, $holderKey);

        if (in_array($seat->id, $heldSeatIds, true)) {
            $locks->release($this->screening, $seat, $holderKey);

            return;
        }

        if (count($heldSeatIds) >= SeatLockService::MAX_SEATS_PER_HOLDER) {
            $this->messageKey = 'front.reservation.errors.seat_limit';

            return;
        }

        // 別のタブで他の上映回を開くと、そちらの `mount()` がこの回のロックを解放し、
        // 以後この画面の `acquire()` は条件6（1上映回まで）で失敗し続ける。
        // 「他のお客様が選択中」と表示すると原因を誤らせるため、専用の案内を出す（4.3.9）。
        if ($this->holdsOtherScreening($holderKey)) {
            $this->messageKey = 'front.reservation.errors.other_screening';

            return;
        }

        if (! $locks->acquire($this->screening, $seat, $holderKey)) {
            $this->messageKey = 'front.reservation.errors.lock_failed';
        }
    }

    /**
     * 座席表の定期更新（6.4.3-1）。**ポーリングでのみ呼ばれる**ため、利用者の操作を
     * 伴わずに保持座席が減った場合をロックの期限切れとして検出できる（7.17）。
     * 検出しないと、確保期限の切れた座席が画面上から無言で外れる。
     *
     * 他のお客様が有効なロックを奪うことはない（4.3.8 条件8）ため、保持座席が減る要因は
     * ①期限切れ ②別タブで他の上映回の P-31 を開いた（そちらの `mount()` が解放する）
     * ③`transfer()`（ログイン時。この画面から離れる）のいずれかである。②は原因が異なる
     * ため、`toggle()` と同じく専用の案内に振り分ける（4.3.9）。
     */
    public function refreshSeatMap(SeatLockService $locks): void
    {
        if (! $this->screening->isOnSale()) {
            return;
        }

        $holderKey = $locks->holderKey();

        if (count($locks->heldSeatIds($this->screening, $holderKey)) >= $this->heldSeatCount) {
            return;
        }

        $this->messageKey = $this->holdsOtherScreening($holderKey)
            ? 'front.reservation.errors.other_screening'
            : 'front.reservation.errors.lock_expired';
    }

    /**
     * 次へ進む（同意画面 P-32、7.7）。座席を1席も保持していない場合は進めない。
     */
    public function proceed(SeatLockService $locks): void
    {
        $this->messageKey = null;

        if (! $this->screening->isOnSale()) {
            $this->messageKey = 'front.reservation.errors.out_of_sale';

            return;
        }

        if ($locks->heldSeatIds($this->screening, $locks->holderKey()) === []) {
            $this->messageKey = 'front.reservation.errors.no_seats';

            return;
        }

        $this->redirect(route('front.reservation.agreement', ['id' => $this->screening->id]), navigate: false);
    }

    public function render(SeatLockService $locks): View
    {
        // 本コンポーネントのビューは上映情報を描画しない（作品名・シアター等はページ側
        // `front/reservation/seats.blade.php` が持ち、コントローラが読み込み済み）。
        // ここで関連を読み込むと、10秒ごとのポーリングのたびに不要なクエリが増える。
        $holderKey = $locks->holderKey();
        $onSale = $this->screening->isOnSale();
        $seats = $onSale ? $this->seats() : new EloquentCollection;
        $heldSeatIds = $onSale ? $locks->heldSeatIds($this->screening, $holderKey) : [];
        $this->heldSeatCount = count($heldSeatIds);

        return view('front.reservation.seat-selection', [
            'onSale' => $onSale,
            'seats' => $seats,
            'states' => $this->states($seats, $heldSeatIds, $holderKey),
            'selectedSeats' => $seats->whereIn('id', $heldSeatIds)->values(),
            // 枚数はロックの実体を正とする（6.4.3-2）。座席表に描画できない座席を
            // 保持していても、上限判定（`toggle()`）と件数表示を食い違わせない。
            'selectedCount' => count($heldSeatIds),
            'maxSeats' => SeatLockService::MAX_SEATS_PER_HOLDER,
            // 座席が1件も無いシアター（データの異常）でも CSS の repeat() を壊さない。
            'columnCount' => max(1, (int) $seats->max('grid_col')),
        ]);
    }

    /**
     * 座席表に描画する座席（使用不可の座席は通路と同じ空きマスとして扱う。6.2 / seat-map スキル）。
     * 並び順は描画順であり、Tab キーの移動順（7.6.4-1）でもある。
     *
     * @return EloquentCollection<int, Seat>
     */
    private function seats(): EloquentCollection
    {
        return Seat::query()
            ->available()
            ->with('seatType')
            ->where('theater_id', $this->screening->theater_id)
            ->orderBy('grid_row')
            ->orderBy('grid_col')
            ->get();
    }

    /**
     * 座席IDごとの表示状態（7.6.2）。
     *
     * 「有効なロックが存在するか」の読み取りは `SeatLock::active()` スコープを
     * 経由する限りサービスを介さなくてよい（13.4.6）。
     *
     * @param  EloquentCollection<int, Seat>  $seats
     * @param  array<int, int>  $heldSeatIds
     * @return Collection<int, SeatSelectionState>
     */
    private function states(EloquentCollection $seats, array $heldSeatIds, string $holderKey): Collection
    {
        if ($seats->isEmpty()) {
            return collect();
        }

        $reserved = ReservationSeat::query()
            ->occupying()
            ->where('screening_id', $this->screening->id)
            ->pluck('seat_id');

        $lockedByOthers = SeatLock::query()
            ->active()
            ->where('screening_id', $this->screening->id)
            ->where('holder_key', '!=', $holderKey)
            ->pluck('seat_id');

        $occupied = $reserved->merge($lockedByOthers)->unique()->flip();

        return $seats->mapWithKeys(fn (Seat $seat): array => [
            $seat->id => SeatSelectionState::of(
                in_array($seat->id, $heldSeatIds, true),
                $occupied->has($seat->id),
            ),
        ]);
    }

    /**
     * 他の上映回のロックを保持しているか（`acquire()` の条件6、4.3.4）。
     */
    private function holdsOtherScreening(string $holderKey): bool
    {
        return SeatLock::query()
            ->active()
            ->where('holder_key', $holderKey)
            ->where('screening_id', '!=', $this->screening->id)
            ->exists();
    }
}
