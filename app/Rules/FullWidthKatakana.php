<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * フリガナの文字集合（4.3.6「全角カタカナのみ」）。
 *
 * **登録側（P-34 非会員の入力・P-02 会員登録）と検索側（A-11）が同一の定義を参照する**
 * ことが本クラスの目的（旧12章 残課題19）。登録側が検索側より広い文字集合を許すと、
 * その氏名は A-11 のフリガナ検索で入力できず、窓口から引き当てられない予約が生まれる。
 *
 * 文字集合は次で構成する。
 *
 * | 範囲 | 含むもの |
 * |---|---|
 * | `ァ-ヶ` | 全角カタカナ（小書き・`ヴ`・`ヵヶ` を含む） |
 * | `ー` | 長音符（U+30FC） |
 * | `ヽヾ` | 繰り返し記号（U+30FD, U+30FE） |
 * | `・` | 中黒（U+30FB。「ジョン・スミス」等の区切り） |
 * | `　`（U+3000）・半角空白 | 姓名の区切り |
 *
 * 濁点・半濁点の合成用文字（U+309B / U+309C）と半角カタカナは含めない。前者は
 * `ガ` 等が単一の符号位置で表せるため入力経路が限られ、後者は 4.3.6 が全角を指定する。
 */
class FullWidthKatakana implements ValidationRule
{
    /**
     * フリガナとして許す文字列の正規表現。**フリガナの定義はこの1箇所に限る。**
     *
     * 通常は本クラスをルールとして使えばよく、参照する必要は無い。公開しているのは、
     * 検索条件の組み立てなど検証以外の用途が出た場合に、同じ定義を使えるようにするため。
     */
    public const string PATTERN = '/\A[ァ-ヶーヽヾ・　 ]+\z/u';

    /**
     * @param  string  $messageKey  検証に失敗したときの文言キー。画面ごとに語調が異なるため
     *                              （7.17 の顧客向けと管理画面）、呼び出し側から差し替える。
     */
    public function __construct(
        private readonly string $messageKey = 'validation.rules.full_width_katakana',
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 文字列以外（配列・null など）は他のルール（`required` / `string`）が扱う。
        // ここで弾くと「全角カタカナで入力してください」が型の誤りにも出てしまう。
        if (! is_string($value)) {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail($this->messageKey)->translate();
        }
    }
}
