<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * TMDB API 連携（8.1）。映画マスタ（A-05、4.8.5）のタイトル検索と
 * メタデータ取り込みに使用する。
 *
 * 8.1 の「取得データをDBに保持し、画面表示のたびにAPIを呼び出さない」は、
 * 取り込み結果を `m_movies` へ保存することで満たす。本サービス自体は
 * キャッシュを持たず、管理者が明示的に検索・取り込みを行ったときにのみ呼ばれる。
 *
 * 認証は v3 の `api_key` クエリパラメータ方式を用いる（15.1 の `TMDB_API_KEY`）。
 * 応答本文・リクエストURLにはAPIキーが含まれうるため、`Http` の `throw()` は
 * 使わず、失敗はすべて `TmdbException` へ置き換えて送出する（17.9-1）。
 */
class TmdbService
{
    /** 一覧・検索結果のサムネイル用の画像サイズ（TMDBの `t/p/{size}`）。 */
    public const string POSTER_SIZE_THUMBNAIL = 'w185';

    /**
     * `m_movies.poster_path` に保存を許すTMDBの相対パスの形式。
     * 保存時のバリデーション（A-05）とURL生成（`posterUrl()`）の双方から参照し、
     * 片方だけが緩む状態を作らない。拡張子を必須にすることで `/..` のような
     * ディレクトリ参照を弾く。
     */
    public const string POSTER_PATH_REGEX = '#\A/[A-Za-z0-9_-]+\.[A-Za-z]{2,4}\z#';

    /**
     * 検索結果の表示件数の上限。TMDBは1ページ20件を返す。管理者がタイトルで
     * 目的の作品を選ぶ用途のため、ページングは行わず1ページ目のみを扱う。
     */
    private const int SEARCH_RESULT_LIMIT = 20;

    /**
     * タイトルで検索する（4.8.5）。返す `TmdbMovie` は上映時間・ジャンルを持たない
     * （`/search/movie` の応答に含まれないため）。
     *
     * @return array<int, TmdbMovie>
     *
     * @throws TmdbException
     */
    public function search(string $query): array
    {
        $payload = $this->send('/search/movie', [
            'query' => $query,
            'include_adult' => 'false',
            'page' => 1,
        ]);

        $results = $payload['results'] ?? null;

        if (! is_array($results)) {
            return [];
        }

        $movies = [];

        foreach (array_slice($results, 0, self::SEARCH_RESULT_LIMIT) as $result) {
            if (is_array($result)) {
                $movies[] = $this->toMovie($result);
            }
        }

        return $movies;
    }

    /**
     * TMDB ID で1作品を取得する。該当が無い場合は `null` を返す。
     *
     * @throws TmdbException
     */
    public function find(int $tmdbId): ?TmdbMovie
    {
        $payload = $this->send('/movie/'.$tmdbId, [], allowNotFound: true);

        if ($payload === null) {
            return null;
        }

        return $this->toMovie($payload);
    }

    /**
     * ポスター画像のURL。`$posterPath` はTMDBが返す先頭スラッシュ付きの相対パス。
     * 相対パス以外（絶対URL・`javascript:` 等）は `m_movies.poster_path` の
     * バリデーションで弾いているが、ここでも連結せず `null` を返す（17.5.2-4と同趣旨）。
     */
    public static function posterUrl(?string $posterPath, string $size = self::POSTER_SIZE_THUMBNAIL): ?string
    {
        if ($posterPath === null || preg_match(self::POSTER_PATH_REGEX, $posterPath) !== 1) {
            return null;
        }

        /** @var string $imageBaseUrl */
        $imageBaseUrl = config('services.tmdb.image_base_url');

        return $imageBaseUrl.'/'.$size.$posterPath;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return ($allowNotFound is true ? array<string, mixed>|null : array<string, mixed>)
     *
     * @throws TmdbException
     */
    private function send(string $path, array $query, bool $allowNotFound = false): ?array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            throw TmdbException::notConfigured();
        }

        /** @var string $baseUrl */
        $baseUrl = config('services.tmdb.base_url');
        /** @var string $language */
        $language = config('services.tmdb.language');
        /** @var int $timeout */
        $timeout = config('services.tmdb.timeout');

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout($timeout)
                ->acceptJson()
                ->get($path, [...$query, 'api_key' => $apiKey, 'language' => $language]);
        } catch (ConnectionException|GuzzleException) {
            // GuzzleException も捕捉するのは、リダイレクト上限超過（TooManyRedirectsException）等の
            // 例外メッセージがリクエストURI（＝クエリパラメータのAPIキー）を含むため（17.9-1）。
            throw TmdbException::requestFailed();
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TmdbException
     */
    private function decode(Response $response): array
    {
        if ($response->failed()) {
            throw TmdbException::requestFailed();
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw TmdbException::requestFailed();
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toMovie(array $data): TmdbMovie
    {
        return new TmdbMovie(
            tmdbId: is_numeric($data['id'] ?? null) ? (int) $data['id'] : 0,
            title: is_string($data['title'] ?? null) ? $data['title'] : '',
            originalTitle: $this->nonEmptyString($data['original_title'] ?? null),
            synopsis: is_string($data['overview'] ?? null) ? $data['overview'] : '',
            posterPath: $this->nonEmptyString($data['poster_path'] ?? null),
            // 公開前の作品は runtime が 0 または null になる。4.8.5 が公開日を過去に
            // 限っているため通常は値を持つが、取り込み後の入力欄で補正できるようにする。
            runtimeMinutes: is_numeric($data['runtime'] ?? null) && (int) $data['runtime'] > 0 ? (int) $data['runtime'] : null,
            releasedOn: $this->toDate($data['release_date'] ?? null),
            genres: $this->toGenres($data['genres'] ?? null),
        );
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function toDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Exception) {
            // TMDBが想定外の形式を返した場合は未取得として扱い、画面で補正させる。
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function toGenres(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $genres = [];

        foreach ($value as $genre) {
            $name = is_array($genre) ? $this->nonEmptyString($genre['name'] ?? null) : null;

            if ($name !== null) {
                $genres[] = $name;
            }
        }

        return $genres;
    }

    private function apiKey(): string
    {
        $apiKey = config('services.tmdb.api_key');

        return is_string($apiKey) ? trim($apiKey) : '';
    }
}
