<?php

namespace App\Services;

use RuntimeException;

/**
 * TMDB API 連携（8.1）の失敗を表す例外。
 *
 * 例外メッセージそのものを画面に出さず、`$messageKey` の言語ファイルキーを
 * 画面側で `__()` して表示する（20.1）。TMDB の応答本文やリクエストURLには
 * APIキーが含まれうるため、`Http` の `throw()` による例外（応答本文を
 * メッセージに含む）をそのまま送出せず、本例外へ置き換える（17.9-1）。
 *
 * `app/Exceptions/` を新設せず `app/Services/` に置く（13.1 のディレクトリ構成に
 * 無いベースディレクトリを増やさないため。4.8.6追記表）。
 */
class TmdbException extends RuntimeException
{
    private function __construct(public readonly string $messageKey, string $message)
    {
        parent::__construct($message);
    }

    /** APIキー（`TMDB_API_KEY`、15.1）が未設定。 */
    public static function notConfigured(): self
    {
        return new self('admin.movie.errors.tmdb_not_configured', 'TMDB API key is not configured.');
    }

    /** 通信失敗、またはTMDBが2xx以外を返した。 */
    public static function requestFailed(): self
    {
        return new self('admin.movie.errors.tmdb_request_failed', 'The request to TMDB failed.');
    }
}
