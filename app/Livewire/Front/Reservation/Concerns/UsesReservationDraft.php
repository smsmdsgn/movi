<?php

namespace App\Livewire\Front\Reservation\Concerns;

use App\Services\ReservationDraft;

/**
 * 予約フローの Livewire 画面が `ReservationDraft`（13.4.7 / 4.3.12）を使う方法。
 *
 * **公開プロパティにも Livewire の依存注入にも載せない。**
 *
 * - プロパティに置くとコンポーネントの状態として直列化され、セッションの内容が
 *   クライアントへ渡ったうえ、次のリクエストで古い値を使う
 * - `mount()` / アクション / `render()` のすべてが同意の有無を見るため、引数で
 *   受け渡すと private メソッドまで含めて署名が連鎖的に膨らむ
 *
 * サービス自身は状態を持たず、呼ばれた時点のセッションを読むため、都度の解決でよい。
 */
trait UsesReservationDraft
{
    protected function draft(): ReservationDraft
    {
        return app(ReservationDraft::class);
    }
}
