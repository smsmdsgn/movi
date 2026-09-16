<?php

use App\Rules\FullWidthText;
use Illuminate\Support\Facades\Validator;

/*
 * 氏名の文字集合（4.3.6 / 7.9「全角文字」）。P-34 と P-02（残課題26）が共有する。
 */

function validateFullWidth(mixed $value, ?string $messageKey = null): Illuminate\Validation\Validator
{
    $rule = $messageKey === null ? new FullWidthText : new FullWidthText($messageKey);

    return Validator::make(['name' => $value], ['name' => [$rule]]);
}

it('全角文字を受け付ける', function (string $value) {
    expect(validateFullWidth($value)->passes())->toBeTrue();
})->with([
    '漢字' => ['祇園太郎'],
    'ひらがな' => ['ぎおんたろう'],
    'カタカナ' => ['ギオンタロウ'],
    '全角スペース区切り' => ['祇園　太郎'],
    '全角英字' => ['Ｔａｒｏ'],
    '全角数字' => ['祇園太郎２'],
    // 範囲を列挙せず半角の否定として定義しているのは、これらを落とさないため。
    '異体字（髙）' => ['髙橋太郎'],
    '異体字（﨑）' => ['山﨑花子'],
    '踊り字' => ['佐々木'],
    '長音符を含む名' => ['マリーアントワネット'],
]);

it('半角文字を含む値を拒否する', function (mixed $value) {
    expect(validateFullWidth($value)->passes())->toBeFalse();
})->with([
    '半角英字' => ['Taro'],
    '半角英字の混在' => ['祇園Taro'],
    '半角数字の混在' => ['祇園太郎2'],
    '半角スペース区切り' => ['祇園 太郎'],
    '半角カタカナ' => ['ｷﾞｵﾝ'],
    '半角記号' => ['祇園太郎!'],
]);

it('空・文字列でない値は判定せず、必須と型は他のルールに委ねる', function (mixed $value) {
    expect(validateFullWidth($value)->passes())->toBeTrue();
})->with([
    'null' => [null],
    '空文字' => [''],
    '配列' => [[['祇園太郎']]],
]);

it('既定の文言はキーではなく翻訳済みの文字列を返す', function () {
    // `:attribute` は `validation.attributes` の表示名（name → 氏名）に置換される。
    expect(validateFullWidth('Taro')->errors()->first('name'))
        ->toBe(__('validation.rules.full_width_text', ['attribute' => __('validation.attributes.name')]))
        ->not->toBe('validation.rules.full_width_text');
});

it('文言キーを呼び出し側から差し替えられる', function () {
    expect(validateFullWidth('Taro', 'front.reservation.customer.errors.name')->errors()->first('name'))
        ->toBe(__('front.reservation.customer.errors.name'));
});
