<?php

namespace App\Livewire\Front\Concerns;

use App\Enums\CancellationOutcome;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Locked;

/**
 * 予約キャンセルの導線（4.4 / 4.3.18）。**予約照会（P-07）とマイページの予約詳細
 * （P-06）が共有する。**
 *
 * **到達の根拠は使う側が決める。** P-07 は照合の結果（`matchedIds`）を、P-06 は
 * 所有者の一致を根拠とする（4.3.18「到達の根拠」/ 17.2.1-1）。本トレイトは
 * `cancellableReservation()` が返したものだけを対象とし、根拠そのものは持たない。
 *
 * **4.4 の条件（期限・入場済み・状態）は判定しない。** `ReservationService::cancel()`
 * がトランザクションの内側で判定し直す（4.3.18「条件の判定場所」）。ここで組み立てる
 * `cancelState()` は導線の出し分けのためだけのものである。
 *
 * 確認・成立の案内・拒否の理由は**真偽値ではなく予約IDで持つ**（4.3.18）。P-07 は
 * 1つのコンポーネントで複数の予約を行き来するため、「確認中である」ことだけを持つと
 * 別の予約に対する確認として引き継がれる。P-06 は1件しか扱わないが、判定を2系統に
 * 分けない。
 */
trait CancelsReservation
{
    /**
     * キャンセルの確認を出している予約のID（4.4 / 4.3.18）。
     *
     * 対象の予約と一致する場合にのみ確認とみなす。同じ予約を開き直した場合に確認が
     * 復元されるのは仕様とする（確定ボタンの明示的なクリックは依然必要であり、確認の
     * 文言も同時に描かれる）。
     */
    #[Locked]
    public ?int $confirmingCancelId = null;

    /**
     * キャンセルを試みた予約のID（4.4）。
     *
     * 結果の文言（`cancelNoticeKey` / `cancelErrorKey`）と対で持つ。**結果が別の予約へ
     * 持ち越されないようにする**ためであり、理由は `confirmingCancelId` と同じ。
     */
    #[Locked]
    public ?int $cancelResultId = null;

    /**
     * キャンセルが成立した場合に出す案内の文言キー（4.4）。
     *
     * 拒否（`cancelErrorKey`）と分けて持つ。**成立したが返金が未了**という、失敗では
     * ないが注意を要する結果があるため（4.3.18）。
     */
    #[Locked]
    public ?string $cancelNoticeKey = null;

    /**
     * キャンセルを拒否された場合の文言キー（4.4）。
     *
     * **`addError()` を使わない。** Livewire の errorBag はスナップショットで次の
     * リクエストへ持ち越され、別の予約を開いても残る（4.3.18）。
     */
    #[Locked]
    public ?string $cancelErrorKey = null;

    /**
     * キャンセルは成立したが返金が未了か（4.3.18）。
     *
     * 見出しと配色を成立（全額返金）と分けるために持つ。**同じ見た目で出すと、
     * 劇場への連絡が要る状態と、何もしなくてよい状態が区別できない。**
     */
    #[Locked]
    public bool $cancelRefundPending = false;

    /**
     * キャンセルの対象としてよい予約。到達の根拠を満たさなければ `null` を返すこと。
     *
     * 明細の描画にも使うため、ビューが触れる関連を読み込んだうえで返す。
     */
    abstract protected function cancellableReservation(): ?Reservation;

    /**
     * 確認を出してよい予約のID。**明細を読み込まずに根拠だけを確かめる。**
     *
     * 確認の開閉は表示の切り替えでしかないため、そのたびに明細を読み直さない
     * （`cancellableReservation()` を呼ぶと `render()` と合わせて2度読むことになる）。
     */
    abstract protected function cancellableReservationId(): ?int;

    /** キャンセルの確認を開く（4.3.18「誤操作への手当て」）。 */
    public function startCancel(): void
    {
        $reservationId = $this->cancellableReservationId();

        if ($reservationId === null) {
            return;
        }

        // 前回の結果（拒否の理由・成立の案内）を消す。`unavailable`（再試行の枯渇）は
        // ボタンが残る唯一の拒否であり、消さないと理由が出たまま確認が開く。
        $this->clearCancelResult();
        $this->confirmingCancelId = $reservationId;
    }

    /** 確認をやめて明細へ戻る。 */
    public function abortCancel(): void
    {
        $this->confirmingCancelId = null;
    }

    /**
     * キャンセルを実行する（4.4）。
     *
     * ここでは到達の根拠と確認の有無だけを確かめる。**4.4 の条件（期限・入場済み・
     * 状態）は `ReservationService` がトランザクションの内側で判定し直す**（4.3.18）。
     * 画面を開いてからボタンを押すまでに期限を過ぎうるため、画面側の判定を信用しない。
     */
    public function cancel(ReservationService $reservations): void
    {
        $reservation = $this->cancellableReservation();

        // **確認が「この予約に対して」出されたものであることを確かめる。** 別の予約で
        // 確認を出したまま画面を移った場合に、確認を経ずにキャンセルさせない（4.3.18）。
        if ($reservation === null || $this->confirmingCancelId !== $reservation->id) {
            return;
        }

        $result = $reservations->cancel($reservation);

        $this->confirmingCancelId = null;
        $this->cancelResultId = $reservation->id;

        $rejected = $result->outcome === CancellationOutcome::Rejected;

        // **文言の出し分けはサービスが済ませている**（4.3.18）。画面は成立と拒否の
        // どちらとして出すかだけを決める。
        $this->cancelNoticeKey = $rejected ? null : $result->messageKey;
        $this->cancelErrorKey = $rejected ? $result->messageKey : null;
        $this->cancelRefundPending = $result->outcome === CancellationOutcome::CancelledWithoutRefund;
    }

    /** キャンセルの結果（成立の案内・拒否の理由）を破棄する。 */
    protected function clearCancelResult(): void
    {
        $this->cancelResultId = null;
        $this->cancelNoticeKey = null;
        $this->cancelErrorKey = null;
        $this->cancelRefundPending = false;
    }

    /**
     * キャンセルの節（`x-front.reservation.cancel-section`）へ渡す値。
     *
     * 確認・成立の案内・拒否の理由は**対象の予約のものだけ**を出す（4.3.18）。
     * 持ち越しを画面側で断つことで、状態を落とし忘れても別の予約へ漏れない。
     *
     * **キーに public プロパティと同じ名前を使わないこと。** Livewire は `render()` が
     * 返したビューへ**後から public プロパティを上書きマージする**ため
     * （`HandleComponents::render()` → `Illuminate\View\View::with()`）、同名にすると
     * ここで絞った値が生のプロパティで置き換わり、**絞り込みが黙って無効になる。**
     * 返金未了の別を `refundPending` という別名で渡すのはこのためである。
     *
     * @return array{cancelState: array{available: bool, noticeKey: string|null}|null, confirmingCancel: bool, cancelledNotice: string|null, cancelError: string|null, refundPending: bool}
     */
    protected function cancelViewData(?Reservation $reservation): array
    {
        // **結果に属する値はまとめて絞る。** 文言だけを絞って返金未了の別（配色と見出しを
        // 分ける根拠）を素通しにすると、別の予約へ赤い「返金未了」が漏れる（4.3.18）。
        $holdsResult = $reservation !== null && $this->cancelResultId === $reservation->id;

        return [
            'cancelState' => $reservation === null ? null : $this->cancelState($reservation),
            'confirmingCancel' => $reservation !== null && $this->confirmingCancelId === $reservation->id,
            'cancelledNotice' => $holdsResult ? $this->cancelNoticeKey : null,
            'cancelError' => $holdsResult ? $this->cancelErrorKey : null,
            'refundPending' => $holdsResult && $this->cancelRefundPending,
        ];
    }

    /**
     * キャンセルの導線をどう出すか（4.4 / 7.19-8）。
     *
     * `null` を返すのは、そもそも導線を出さない場合（キャンセル済み）。
     *
     * @return array{available: bool, noticeKey: string|null}|null
     */
    private function cancelState(Reservation $reservation): ?array
    {
        if ($reservation->status !== ReservationStatus::Paid) {
            return null;
        }

        // 4.4-5。入場済みは期限より先に判定する。期限内であっても入場していれば
        // 「期限を過ぎた」という案内は事実と異なる。
        if ($reservation->isCheckedIn()) {
            return ['available' => false, 'noticeKey' => 'front.cancel.unavailable.checked_in'];
        }

        // 4.4-1。期限の規則は `Screening` が持つ（`ReservationService::cancel()` の
        // 判定と同じものを使う。境界の向きを画面側に複製しない）。
        if (! $reservation->screening->acceptsCancellationAt(Date::now())) {
            return ['available' => false, 'noticeKey' => 'front.cancel.unavailable.deadline'];
        }

        return ['available' => true, 'noticeKey' => null];
    }
}
