<?php

namespace App\Livewire\Front\Reservation\Concerns;

use App\Services\SeatLockService;

/**
 * 同意後の予約フロー（P-33・P-34・P-35）が共通して持つ、先へ進める前提の判定（4.3.12 / 4.3.13）。
 *
 * 前提は3つあり、いずれも**利用者の直前の操作ではなく描画時点の状態**で決まる。
 *
 * | 前提 | 満たさない場合の復帰先 |
 * |---|---|
 * | 販売期間内の上映回である | 無し（操作を残さない） |
 * | その回の座席を保持している | 座席選択（P-31） |
 * | 利用規約に同意している | 同意画面（P-32） |
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

        if ($locks->heldSeatIds($screening, $locks->holderKey()) === []) {
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

        return $this->status(null, null, null);
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
