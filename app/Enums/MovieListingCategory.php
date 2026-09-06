<?php

namespace App\Enums;

/**
 * 館トップの作品一覧タブの区分（7.3 / 4.2.1）。表示専用の区分だが、
 * 13.3 に従い文字列リテラルを散らさず backed enum で扱う。
 */
enum MovieListingCategory: string
{
    /** 上映開始日 ≦ 当日 ≦ 上映終了日 */
    case Now = 'now';

    /** 上映開始日 > 当日 */
    case Upcoming = 'upcoming';

    /** 上映終了日 < 当日 */
    case Ended = 'ended';

    /** タブ見出しの言語ファイルキー（`lang/ja/front.php`）。 */
    public function labelKey(): string
    {
        return 'front.cinema_top.tabs.'.$this->value;
    }

    /** 該当作品が無い場合の文言キー。 */
    public function emptyKey(): string
    {
        return 'front.cinema_top.empty.'.$this->value;
    }
}
