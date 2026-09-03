<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * TMDB から取得した1作品分のデータ（8.1）。`m_movies` へ取り込む前の値を保持する
 * 値オブジェクト（13.4.5 の `PriceBreakdown` と同じ扱い）。
 *
 * 検索（`TmdbService::search()`）と詳細取得（`TmdbService::find()`）で同じ型を返すが、
 * `/search/movie` の応答は上映時間・ジャンルを含まないため、検索由来の値では
 * `$runtimeMinutes` が `null`、`$genres` が空配列になる。取り込みは必ず
 * `find()` を経由して欠けた項目を補う。
 *
 * 表示範囲は 7.5 の10項目に確定しており（旧12章 未決事項2。解決済みのため削除）、
 * 監督・キャスト等の追加取得は行わない。
 */
final readonly class TmdbMovie
{
    /**
     * @param  array<int, string>  $genres
     */
    public function __construct(
        public int $tmdbId,
        public string $title,
        public ?string $originalTitle,
        public string $synopsis,
        public ?string $posterPath,
        public ?int $runtimeMinutes,
        public ?CarbonImmutable $releasedOn,
        public array $genres,
    ) {}

    /**
     * ポスター画像のURL。未取得の場合は `null` を返す。
     */
    public function posterUrl(string $size = TmdbService::POSTER_SIZE_THUMBNAIL): ?string
    {
        return TmdbService::posterUrl($this->posterPath, $size);
    }
}
