<?php

namespace App\Enums;

/**
 * 予約確定の試行の結果（8.2 / 13.4.7）。画面（P-37）はこの4つで分岐する。
 *
 * 文字列値を持たせるのは、Livewire の公開プロパティに載せずとも
 * ログ・テストで読める形にしておくため（13.3）。
 */
enum PaymentOutcome: string
{
    /** 課金が成立し、予約が `paid` になった。予約完了（P-38）へ進む。 */
    case Confirmed = 'confirmed';

    /** 追加認証（3Dセキュア）が必要。ブラウザで認証を終えてから再検証する。 */
    case RequiresAuthentication = 'requires_authentication';

    /** カードが拒否された等。決済画面（P-36）でカードを入れ直す（8.2「決済失敗時の扱い」）。 */
    case Failed = 'failed';

    /**
     * 課金の成立後に座席を確保できなかった（ロックの喪失・一意制約違反）。
     * **返金のうえ**座席選択（P-31）からやり直す（8.2 手順1）。
     */
    case SeatsUnavailable = 'seats_unavailable';
}
