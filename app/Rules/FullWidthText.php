<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 氏名の文字集合（4.3.6 / 7.9「全角文字」）。
 *
 * 「全角」を**半角の否定**として定義する。漢字・ひらがな・カタカナ・全角英数の範囲を
 * 列挙すると、異体字や結合文字を持つ実在の氏名を落とす（`髙`・`﨑` など）。
 *
 * 除外するもの:
 *
 * | 範囲 | 内容 |
 * |---|---|
 * | U+0000〜U+007F | 制御文字・半角英数・半角記号・半角空白 |
 * | U+FF61〜U+FF9F | 半角カタカナ（7.9 は全角を指定する） |
 *
 * 全角空白（U+3000）は姓名の区切りとして許す。フリガナの文字集合は
 * 検索側と揃える必要があるため別扱いとする（`FullWidthKatakana`）。
 */
class FullWidthText implements ValidationRule
{
    /** 氏名として許す文字列の正規表現。 */
    public const string PATTERN = '/\A[^\x00-\x7F\x{FF61}-\x{FF9F}]+\z/u';

    /**
     * @param  string  $messageKey  検証に失敗したときの文言キー。
     */
    public function __construct(
        private readonly string $messageKey = 'validation.rules.full_width_text',
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 文字列以外は `required` / `string` が扱う（`FullWidthKatakana` と同じ整理）。
        if (! is_string($value)) {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail($this->messageKey)->translate();
        }
    }
}
