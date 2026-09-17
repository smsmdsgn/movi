<?php

namespace App\Livewire\Front\Reservation\Concerns;

use App\Models\TicketType;
use App\Services\SeatLockService;
use Illuminate\Support\Facades\Auth;

/**
 * 同意後の予約フロー（P-33〜P-36）が共通して持つ、先へ進める前提の判定（4.3.12 / 4.3.13 / 4.3.14）。
 *
 * 前提は予約フローの順に並び、いずれも**利用者の直前の操作ではなく描画時点の状態**で決まる。
 * 後ろの2つは画面ごとに課すかどうかが異なる（`requiresGuestInput()` / `requiresTicketSelection()`）。
 *
 * | 前提 | 満たさない場合の復帰先 |
 * |---|---|
 * | 販売期間内の上映回である | 無し（操作を残さない） |
 * | その回の座席を保持している | 座席選択（P-31） |
 * | 利用規約に同意している | 同意画面（P-32） |
 * | 非会員はお客様情報を入力している | お客様情報の入力（P-34） |
 * | 保持中の全席に実在する券種を割り当てている | 券種選択（P-35） |
 *
 * **復帰先を段階の1つ前に固定しない。** 未同意の利用者を P-31 へ戻すと保持中の座席を
 * 選び直させることになり、期限切れの利用者を P-32 へ送っても同意だけでは先へ進めない。
 *
 * 本トレイトは `ResolvesScreening` と `UsesReservationDraft` を前提とする。
 */
trait GuardsReservationStep
{
    /**
     * 先へ進める状態か。
     *
     * **ロックの外側の判定であり厳密ではない**が、座席在庫は確定時の検証（8.2 手順1）が
     * 最終的に保護する（4.3.9 と同じ整理）。
     */
    protected function canProceed(SeatLockService $locks): bool
    {
        return $this->stepStatus($locks)['noticeKey'] === null;
    }

    /**
     * 画面に出す案内と復帰先（7.17）。満たしていない前提のうち、最も手前のものを示す。
     *
     * 案内と復帰先を1つの判定から返す。別々のメソッドに分けると、両者が食い違う経路
     *（「確保期限が過ぎました」と表示しながら同意画面へ戻す等）を作りうる。
     *
     * @return array{noticeKey: string|null, recoveryUrl: string|null, recoveryLabelKey: string|null}
     */
    protected function stepStatus(SeatLockService $locks): array
    {
        $screening = $this->screeningOnSale();

        // 販売できない回（削除済み・販売期間外）では復帰先を出さない。座席を選び直しても
        // この回は買えないため、導線を残すと同じ結果へ往復させる（4.3.10）。
        if ($screening === null) {
            return $this->status('front.reservation.errors.out_of_sale', null, null);
        }

        $heldSeatIds = $locks->heldSeatIds($screening, $locks->holderKey());

        if ($heldSeatIds === []) {
            return $this->status(
                // 振り分け（期限切れ／別の上映回）は P-32 と共有する（`ResolvesScreening`）。
                $this->lostSeatsNoticeKey($locks->holderKey()),
                route('front.reservation.seats', ['id' => $this->screeningId]),
                'front.reservation.back_to_seats',
            );
        }

        // 座席は保持しているが P-32 を経ていない（URLへの直接到達）。同意は座席の
        // 選び直しを伴わないため、復帰先は P-31 ではなく P-32 とする。
        if (! $this->draft()->hasAgreed($this->screeningId)) {
            return $this->status(
                'front.reservation.errors.agreement_required',
                route('front.reservation.agreement', ['id' => $this->screeningId]),
                'front.reservation.back_to_agreement',
            );
        }

        // 非会員がお客様情報（P-34）を入力していない。P-32 の後に P-35 以降のURLへ直接
        // 到達すると、連絡先を持たないまま決済へ進める（12章 残課題25-a）。
        if ($this->requiresGuestInput() && Auth::guest() && $this->draft()->guest($this->screeningId) === null) {
            return $this->status(
                'front.reservation.errors.customer_info_required',
                route('front.reservation.customer', ['id' => $this->screeningId]),
                'front.reservation.back_to_customer',
            );
        }

        // 券種（P-35）が保持中の全席ぶん揃っていない。座席を選び直した後に決済のURLへ
        // 直接戻った場合も含む（割り当ては座席に紐づく。4.3.13）。
        if ($this->requiresTicketSelection() && ! $this->hasUsableTickets($heldSeatIds)) {
            return $this->status(
                'front.reservation.errors.ticket_selection_required',
                route('front.reservation.tickets', ['id' => $this->screeningId]),
                'front.reservation.back_to_tickets',
            );
        }

        return $this->status(null, null, null);
    }

    /**
     * 保持中の全席に、券種マスタに実在する券種が割り当てられているか（4.3.14）。
     *
     * **券種IDの実在まで確かめる。** `PricingService::calculate()` は存在しないIDに
     * `InvalidArgumentException` を投げる（13.4.5）ため、記録した後に券種が消えると
     * 画面が500になる。4.3.10 の方針（例外にせず 7.17 の文言を返す）に合わせ、
     * 券種選択（P-35）からのやり直しへ倒す。
     *
     * @param  array<int, int>  $heldSeatIds  `SeatLockService::heldSeatIds()` が返す座席ID
     */
    private function hasUsableTickets(array $heldSeatIds): bool
    {
        $tickets = $this->draft()->tickets($this->screeningId);

        if (array_diff($heldSeatIds, array_keys($tickets)) !== []) {
            return false;
        }

        $ticketTypeIds = array_unique(array_values($tickets));

        return TicketType::query()->whereIn('id', $ticketTypeIds)->count() === count($ticketTypeIds);
    }

    /**
     * 非会員のお客様情報（P-34、4.3.6）を前提とするか。
     *
     * **既定は真とし、P-34 より前の画面（P-33・P-34 自身）だけが外す。** 後から加わる
     * 画面（P-37・P-38）は予約フローの後段にあり、前提を課すのが既定であるべきため。
     */
    protected function requiresGuestInput(): bool
    {
        return true;
    }

    /**
     * 券種の割り当て（P-35、7.10）を前提とするか。
     *
     * **既定は偽とし、P-35 より後の画面だけが課す。** 券種は予約フローの最後の入力で
     * あり、これを前提とする画面は P-36 以降に限られる。
     */
    protected function requiresTicketSelection(): bool
    {
        return false;
    }

    /**
     * @return array{noticeKey: string|null, recoveryUrl: string|null, recoveryLabelKey: string|null}
     */
    private function status(?string $noticeKey, ?string $recoveryUrl, ?string $recoveryLabelKey): array
    {
        return [
            'noticeKey' => $noticeKey,
            'recoveryUrl' => $recoveryUrl,
            'recoveryLabelKey' => $recoveryLabelKey,
        ];
    }
}
