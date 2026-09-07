<?php

namespace App\Enums;

/**
 * 座席表（P-31、7.6.2）における1席の状態。色のみで区別しないため（5.2-3 / 13.5-5）、
 * 記号（`symbol()`）と読み上げ用の文言（`labelKey()`）を状態ごとに持つ。
 *
 * 7.6.2 は「他者がロック中」と「予約済み」を別の行に挙げるが、いずれも
 * 「灰色・クリック不可」と同一の表現を定めており、区別して表示すると
 * 他のお客様の選択状況を推測できてしまう。両者は `Occupied` に統合する（4.3.9）。
 */
enum SeatSelectionState: string
{
    /** 選択可。クリックでロックを取得する */
    case Selectable = 'selectable';

    /** 自分が選択中（ロックを保持している）。再クリックで解除する */
    case Selected = 'selected';

    /** 予約済み、または他のお客様がロック中。選択できない */
    case Occupied = 'occupied';

    /**
     * 1席の状態を判定する（7.6.2）。自分が保持しているロックを最優先とし、
     * 予約済み・他者のロックを `Occupied` に統合する。
     *
     * @param  bool  $isHeld  自分がロックを保持している
     * @param  bool  $isOccupied  予約済み、または他のお客様が有効なロックを保持している
     */
    public static function of(bool $isHeld, bool $isOccupied): self
    {
        return match (true) {
            $isHeld => self::Selected,
            $isOccupied => self::Occupied,
            default => self::Selectable,
        };
    }

    /** 表示記号。色以外の手掛かりとして座席のマスに描画する。 */
    public function symbol(): string
    {
        return match ($this) {
            self::Selectable => '',
            self::Selected => '✓',
            self::Occupied => '×',
        };
    }

    /** 選択・解除の操作を受け付けるか。 */
    public function isOperable(): bool
    {
        return $this !== self::Occupied;
    }

    /** 文言の言語ファイルキー（`lang/ja/front.php`）。 */
    public function labelKey(): string
    {
        return 'front.reservation.seat_state.'.$this->value;
    }
}
