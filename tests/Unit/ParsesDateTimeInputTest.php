<?php

use App\Livewire\Admin\Concerns\ParsesDateTimeInput;
use Carbon\CarbonImmutable;

/**
 * `ParsesDateTimeInput`（A-09・A-12・A-13 が共有する `datetime-local` の解釈）の検証。
 * 3画面が単一の実装に依存するため、**受ける形式・秒の切り捨て・解釈できない値の扱い**を
 * ここで固定する。
 *
 * 画面側の `admin.*.errors.invalid_datetime`（null が返った場合の分岐）は、`rules()` の
 * `date_format:Y-m-d\TH:i,Y-m-d\TH:i:s` が同じ値を先に弾くため到達しない防御的な分岐で
 * ある。そのため解釈の失敗はトレイト単体で検証する。
 */
$parser = new class
{
    use ParsesDateTimeInput;

    public function parse(string $value): ?CarbonImmutable
    {
        return $this->parseDateTime($value);
    }
};

it('分までの形式（ブラウザの既定）を解釈する', function () use ($parser) {
    $parsed = $parser->parse('2026-09-24T10:30');

    expect($parsed)->not->toBeNull()
        ->and($parsed->format('Y-m-d H:i:s'))->toBe('2026-09-24 10:30:00');
});

it('秒を含む形式も受け、秒は切り捨てる', function () use ($parser) {
    // 秒の有無はブラウザにより異なる。`rules()` の `date_format` も両形式を許可している
    // ため、片方だけを受ける状態にすると検証を通った値が解釈できずエラーになる。
    $parsed = $parser->parse('2026-09-24T10:30:45');

    expect($parsed)->not->toBeNull()
        ->and($parsed->second)->toBe(0)
        ->and($parsed->format('Y-m-d H:i:s'))->toBe('2026-09-24 10:30:00');
});

it('存在しない日付を翌月へ繰り上げず null を返す', function () use ($parser) {
    // `createFromFormat` は `2026-09-31` を 10/1 として解釈する。往復させて入力と
    // 一致することを確かめることで弾いている。
    expect($parser->parse('2026-09-31T10:00'))->toBeNull();
});

it('形式の異なる値・空文字は null を返す', function (string $value) use ($parser) {
    expect($parser->parse($value))->toBeNull();
})->with([
    '日付のみ' => ['2026-09-24'],
    '空文字' => [''],
    'スペース区切り' => ['2026-09-24 10:30'],
    '文字列' => ['明日の10時'],
]);
