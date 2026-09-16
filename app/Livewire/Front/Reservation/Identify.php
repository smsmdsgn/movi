<?php

namespace App\Livewire\Front\Reservation;

use App\Livewire\Front\Reservation\Concerns\ResolvesScreening;
use App\Models\Screening;
use App\Services\SeatLockService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

/**
 * 会員／非会員の選択（P-33、7.8）。1画面1コンポーネント（13.4.3）。
 *
 * **会員は本画面を通過する。** ログイン済みの利用者には選ぶものが無いため、券種選択（P-35）
 * へ送る。ログイン後の戻り先を P-33 に固定できるのはこの前送りがあるためで、ログイン画面が
 * 予約フローのどの段階へ戻すかを知らずに済む（4.3.11）。
 *
 * 座席ロックの保持者の移譲（`session:{id}` → `user:{id}`）は `TransferSeatLocksOnLogin`
 * （`Illuminate\Auth\Events\Login`、工程5-bで実装済み）が行う。本画面は移譲元の
 * `holder_key` を持ち回らない（4.3.11）。
 *
 * **操作に対する文言を持たない。** 2つの導線はいずれも前提（販売期間内・座席の保持）を
 * 満たさなければ何もせず、その理由は `render()` が状態から決める（P-32 の `noticeKey()` と
 * 同じ整理。4.3.10）。このため P-31・P-32 が持つ `messageKey` プロパティを置いていない。
 */
class Identify extends Component
{
    use ResolvesScreening;

    public function mount(Screening $screening, SeatLockService $locks): void
    {
        $this->rememberScreening($screening);

        if (Auth::check() && $this->canProceed($locks)) {
            $this->forwardToTickets();
        }
    }

    /**
     * 会員としてログイン（7.8-1）。ログイン後に本画面へ戻し、`mount()` が券種選択へ送る。
     *
     * 戻り先は**サーバー側で組み立てたURLのみ**を `url.intended` へ入れる。リクエスト由来の
     * URLを受け取らないため、外部サイトへ誘導される経路（オープンリダイレクト）を作らない。
     */
    public function login(SeatLockService $locks): void
    {
        if (! $this->canProceed($locks)) {
            return;
        }

        // 別のタブでログインを済ませた後にこのタブの導線を押した場合。`login` は `guest`
        // ミドルウェア配下のため送っても弾かれ、購入フローから外れたうえ `url.intended` が
        // 残る（12章 残課題27）。`mount()` と同じ扱いで券種選択へ送る。
        if (Auth::check()) {
            $this->forwardToTickets();

            return;
        }

        Session::put('url.intended', route('front.reservation.identify', ['id' => $this->screeningId]));

        $this->redirect(route('login'), navigate: false);
    }

    /**
     * 会員登録せずに購入（7.8-3）。お客様情報の入力（P-34、7.9）へ進む。
     */
    public function continueAsGuest(SeatLockService $locks): void
    {
        if (! $this->canProceed($locks)) {
            return;
        }

        $this->redirect(route('front.reservation.customer', ['id' => $this->screeningId]), navigate: false);
    }

    public function render(SeatLockService $locks): View
    {
        $screening = $this->screeningOnSale();
        $hasSeats = $screening !== null && $locks->heldSeatIds($screening, $locks->holderKey()) !== [];

        return view('front.reservation.identify-choices', [
            'onSale' => $screening !== null,
            'hasSeats' => $hasSeats,
            'noticeKey' => $this->noticeKey($screening !== null, $hasSeats, $locks),
            'seatsUrl' => route('front.reservation.seats', ['id' => $this->screeningId]),
        ]);
    }

    /**
     * 券種選択（P-35、7.10）へ送る。会員・非会員のいずれの経路も P-35 で合流する（7.18）。
     */
    private function forwardToTickets(): void
    {
        $this->redirect(route('front.reservation.tickets', ['id' => $this->screeningId]), navigate: false);
    }

    /**
     * 先へ進める状態か（販売期間内の回の座席を保持している）。
     *
     * **ロックの外側の判定であり厳密ではない**が、座席在庫は確定時の検証（8.2 手順1）が
     * 最終的に保護する（4.3.9 と同じ整理）。
     */
    private function canProceed(SeatLockService $locks): bool
    {
        $screening = $this->screeningOnSale();

        return $screening !== null && $locks->heldSeatIds($screening, $locks->holderKey()) !== [];
    }

    /**
     * 画面に出す案内（7.17）。判定と振り分けは P-32 と同じ（4.3.10）。
     */
    private function noticeKey(bool $onSale, bool $hasSeats, SeatLockService $locks): ?string
    {
        if (! $onSale) {
            return 'front.reservation.errors.out_of_sale';
        }

        if ($hasSeats) {
            return null;
        }

        // 別の上映回の座席を保持していると、この回の保持座席は常に0件になる。
        // 「確保期限が過ぎました」と表示すると原因を誤らせる（4.3.10 と同じ振り分け）。
        return $this->holdsOtherScreening($locks->holderKey())
            ? 'front.reservation.errors.other_screening_reselect'
            : 'front.reservation.errors.lock_expired';
    }
}
