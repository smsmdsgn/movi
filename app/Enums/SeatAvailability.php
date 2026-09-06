<?php

namespace App\Enums;

/**
 * 上映回ボタンに表示する空席状況（4.2.2-4 / 7.4）。
 * 記号だけに頼らず、`labelKey()` の文言を併記して色・記号以外でも区別できるようにする（13.5-5）。
 */
enum SeatAvailability: string
{
    /** 残席が全体の30%超 */
    case Available = 'available';

    /** 残席が全体の30%以下（満席を除く） */
    case Few = 'few';

    /** 満席 */
    case Full = 'full';

    /** 上映日の3日前 0:00 に達していない（4.2.2-5。非活性で表示する） */
    case BeforeSale = 'before_sale';

    /**
     * 残席割合の閾値（%）。これ以下を「残りわずか」とする（4.2.2-4）。
     */
    public const int FEW_SEATS_THRESHOLD_PERCENT = 30;

    /**
     * 座席総数と残席数から状態を判定する。販売前の判定は呼び出し側（`ScheduleService`）が行う。
     */
    public static function fromSeatCounts(int $totalSeats, int $remainingSeats): self
    {
        if ($totalSeats <= 0 || $remainingSeats <= 0) {
            return self::Full;
        }

        // 整数演算のみで「残席 ÷ 総数 ≦ 30%」を判定する（浮動小数の丸めに依存しない）。
        if ($remainingSeats * 100 <= $totalSeats * self::FEW_SEATS_THRESHOLD_PERCENT) {
            return self::Few;
        }

        return self::Available;
    }

    /** 上映回ボタンで予約導線を有効にしてよいか。 */
    public function isSelectable(): bool
    {
        return $this !== self::BeforeSale;
    }

    /** 表示記号（○ / △ / ×）。販売前は記号を持たない。 */
    public function symbol(): string
    {
        return match ($this) {
            self::Available => '○',
            self::Few => '△',
            self::Full => '×',
            self::BeforeSale => '',
        };
    }

    /** 文言の言語ファイルキー（`lang/ja/front.php`）。 */
    public function labelKey(): string
    {
        return 'front.schedule.availability.'.$this->value;
    }
}
