<?php

namespace App\Livewire\Front\Reservation\Concerns;

use App\Models\Screening;
use App\Models\SeatLock;
use Livewire\Attributes\Locked;

/**
 * 予約フロー（P-31〜P-37）の Livewire 画面が対象の上映回を解決する方法（4.3.10）。
 *
 * **上映回をモデルとして水和しない。** 公開プロパティに `Screening` を置くと、A-09 が
 * 上映回を削除した後のクリック・ポーリングが `ModelNotFoundException` となり、7.17 に
 * 対応する文言を持たないエラーとして利用者に見える（旧12章 残課題23）。IDだけを保持し、
 * 必要な時点で読み直したうえで、取得できない場合は「販売期間外」と同じ応答にする。
 */
trait ResolvesScreening
{
    /** 対象の上映回ID（4.3.10。モデルとして水和しない）。 */
    #[Locked]
    public int $screeningId;

    /** 同一リクエスト内での読み直しを避けるための保持（Livewire は private を直列化しない）。 */
    private ?Screening $resolvedScreening = null;

    private bool $screeningResolved = false;

    /**
     * `mount()` で受け取った上映回を記録する。ページ側のコントローラが読み込み済みの
     * モデルを渡すため、初回描画では読み直さない。
     */
    protected function rememberScreening(Screening $screening): void
    {
        $this->screeningId = $screening->id;
        $this->resolvedScreening = $screening;
        $this->screeningResolved = true;
    }

    /**
     * 対象の上映回。A-09 が削除済みの場合は null を返す。
     *
     * 現在の呼び出し元は `screeningOnSale()` のみだが、「削除済み」と「販売期間外」を
     * 区別したい画面（P-33 以降）のために使用側へ公開しておく。
     */
    protected function screening(): ?Screening
    {
        if (! $this->screeningResolved) {
            $this->resolvedScreening = Screening::find($this->screeningId);
            $this->screeningResolved = true;
        }

        return $this->resolvedScreening;
    }

    /**
     * 販売できる状態の上映回。削除済み・販売期間外のいずれも null を返す（4.3.10）。
     *
     * 利用者から見て両者は「この回はもう購入できない」という同一の結果であり、
     * 7.17 は「販売期間外」の文言のみを定める。
     *
     * **これはロックの外側の判定であり厳密ではない。** 判定の直後に削除・開始時刻の変更が
     * あっても、座席在庫は `SeatLockService::acquire()` の条件1・4 が最終的に保護する（4.3.9）。
     */
    protected function screeningOnSale(): ?Screening
    {
        $screening = $this->screening();

        return $screening !== null && $screening->isOnSale() ? $screening : null;
    }

    /**
     * 他の上映回のロックを保持しているか（`acquire()` の条件6、4.3.4）。
     *
     * この状態では対象の上映回で1席も取得できず、保持座席も0件になる。原因が
     * 「期限切れ」「他のお客様の選択」と異なるため、画面は専用の案内へ振り分ける（4.3.9 / 4.3.10）。
     *
     * 「有効なロックが存在するか」の読み取りは `SeatLock::active()` スコープを
     * 経由する限りサービスを介さなくてよい（13.4.6）。
     */
    protected function holdsOtherScreening(string $holderKey): bool
    {
        return SeatLock::query()
            ->active()
            ->where('holder_key', $holderKey)
            ->where('screening_id', '!=', $this->screeningId)
            ->exists();
    }

    /**
     * この回の保持座席が0件のときの案内（7.17）。
     *
     * P-32（`Agreement`）と P-33・P-34（`GuardsReservationStep`）が共有する。同じ状態に
     * 別の画面が別の文言を出さないよう、振り分けは1箇所に置く（4.3.12 と同じ趣旨）。
     */
    protected function lostSeatsNoticeKey(string $holderKey): string
    {
        // 別の上映回の座席を保持していると、この回の保持座席は常に0件になる。
        // 「確保期限が過ぎました」と表示すると原因を誤らせる（4.3.9 / 4.3.10）。
        return $this->holdsOtherScreening($holderKey)
            ? 'front.reservation.errors.other_screening_reselect'
            : 'front.reservation.errors.lock_expired';
    }
}
