<?php

namespace App\Enums;

/**
 * お問い合わせフォーム（P-14）の種別（4.9.2）。表示専用の区分だが、
 * 13.3 に従い文字列リテラルを散らさず backed enum で扱う。
 *
 * 採用に関する問い合わせは選択肢に含めず、採用情報ページ（P-12）へ誘導する（4.9.2）。
 */
enum ContactCategory: string
{
    /** お支払い・チケットについて */
    case Payment = 'payment';

    /** 館内設備・上映設備について */
    case Facility = 'facility';

    /** 上映作品・スケジュールについて */
    case Schedule = 'schedule';

    /** 会員・スタンプカードについて */
    case Membership = 'membership';

    /** 落とし物について */
    case LostItem = 'lost_item';

    /** 団体利用・貸切上映について */
    case Group = 'group';

    /** その他 */
    case Other = 'other';

    /** 選択肢の表示名の言語ファイルキー（`lang/ja/front.php`）。 */
    public function labelKey(): string
    {
        return 'front.contact.categories.'.$this->value;
    }
}
