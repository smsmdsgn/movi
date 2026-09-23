<?php

namespace App\Livewire\Admin\Concerns;

use Carbon\CarbonImmutable;

/**
 * `datetime-local` の入力値を解釈する。**A-09（上映回）・A-12（お知らせ）・
 * A-13（バナー）が共有する。**
 *
 * 秒を含む表記も受ける（`datetime-local` が送る値はブラウザにより異なる）。
 * 各画面の `rules()` は `date_format:Y-m-d\TH:i,Y-m-d\TH:i:s` で同じ2形式を許可して
 * おり、**受ける形式をここと突き合わせて増減させること**（片方だけ増やすと、検証を
 * 通った値が解釈できずエラーになる）。
 *
 * 解釈できない場合は null を返す。呼び出し側は画面上のエラーとして扱い、例外にしない
 *（`createFromFormat` は Carbon の strict mode が有効なとき例外を投げ、無効なときは
 * null を返す。既定では有効であり本システムは切り替えていないが、**どちらでも同じ
 * 結果になるよう両方を受けて `continue` する**）。
 */
trait ParsesDateTimeInput
{
    protected function parseDateTime(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed === null) {
                continue;
            }

            // `createFromFormat` は `2026-09-31T10:00` のような日付を翌月へ繰り上げて
            // 解釈するため、往復させて入力と一致することを確かめる。
            if ($parsed->format($format) === $value) {
                return $parsed->seconds(0);
            }
        }

        return null;
    }
}
