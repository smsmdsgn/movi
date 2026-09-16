<?php

use App\Rules\FullWidthKatakana;
use Illuminate\Support\Facades\Validator;

/*
 * フリガナの文字集合（4.3.6）。登録側（P-34・P-02）と検索側（A-11）が同一の定義を
 * 参照することを担保する（旧12章 残課題19）。
 */

function validateKana(mixed $value, ?string $messageKey = null): Illuminate\Validation\Validator
{
    $rule = $messageKey === null ? new FullWidthKatakana : new FullWidthKatakana($messageKey);

    return Validator::make(['kana' => $value], ['kana' => [$rule]]);
}

it('全角カタカナを受け付ける', function (string $value) {
    expect(validateKana($value)->passes())->toBeTrue();
})->with([
    'カタカナ' => ['ケンサクタロウ'],
    '小書き' => ['キャッシュ'],
    '濁点・半濁点' => ['ギンザパピヨン'],
    'ヴ' => ['ヴァイオリン'],
    'ヵヶ' => ['ヵヶ'],
    '長音符' => ['コーヒー'],
    '全角スペース区切り' => ['ケンサク　タロウ'],
    '半角スペース区切り' => ['ケンサク タロウ'],
    '中黒（外国人名の区切り）' => ['ジョン・スミス'],
    '繰り返し記号' => ['スヽメ'],
]);

it('全角カタカナ以外を拒否する', function (mixed $value) {
    expect(validateKana($value)->passes())->toBeFalse();
})->with([
    '漢字' => ['検索'],
    'ひらがな' => ['けんさく'],
    '半角カタカナ' => ['ｹﾝｻｸ'],
    '半角英字' => ['Kensaku'],
    '全角英字' => ['Ｋｅｎｓａｋｕ'],
    '数字' => ['１２３'],
    'カタカナと漢字の混在' => ['ケンサク太郎'],
    '記号' => ['ケンサク！'],
]);

it('空・文字列でない値は判定せず、必須と型は他のルールに委ねる', function (mixed $value) {
    // `required` / `string` が扱う領域。ここで弾くと「全角カタカナで入力してください」が
    // 未入力や型の誤りにも出てしまう。Laravel は空文字に対し暗黙ルール以外を実行しない。
    expect(validateKana($value)->passes())->toBeTrue();
})->with([
    'null' => [null],
    '空文字' => [''],
    '配列' => [[['ケンサク']]],
]);

it('既定の文言はキーではなく翻訳済みの文字列を返す', function () {
    $validator = validateKana('検索');

    // `:attribute` は `validation.attributes` の表示名（kana → フリガナ）に置換される。
    expect($validator->errors()->first('kana'))
        ->toBe(__('validation.rules.full_width_katakana', ['attribute' => __('validation.attributes.kana')]))
        ->not->toBe('validation.rules.full_width_katakana');
});

it('文言キーを呼び出し側から差し替えられる（画面ごとに語調が異なるため）', function () {
    // 顧客向け（P-34）は「ご入力ください」、管理画面（A-11）は「入力してください」（4.3.12）。
    expect(validateKana('検索', 'front.reservation.customer.errors.name_kana')->errors()->first('kana'))
        ->toBe(__('front.reservation.customer.errors.name_kana'));

    expect(validateKana('検索', 'admin.reservation_search.errors.kana_only')->errors()->first('kana'))
        ->toBe(__('admin.reservation_search.errors.kana_only'));
});

it('登録側と検索側が同一の判定になる（旧12章 残課題19）', function (string $value, bool $valid) {
    // 登録側（P-34）はルールオブジェクト、検索側（A-11）も同じクラスを使う。
    // 万一どちらかが `PATTERN` を直接使う形へ戻っても食い違わないことを固定する。
    expect(validateKana($value)->passes())->toBe($valid);
    expect(preg_match(FullWidthKatakana::PATTERN, $value) === 1)->toBe($valid);
})->with([
    '中黒' => ['ジョン・スミス', true],
    'カタカナ' => ['ギオンタロウ', true],
    '漢字' => ['検索', false],
    '半角カタカナ' => ['ｹﾝｻｸ', false],
]);
