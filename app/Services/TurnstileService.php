<?php

namespace App\Services;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile によるボット対策（4.9.2「ボット対策（Cloudflare Turnstile）」）。
 * お問い合わせ（P-14）の送信時に、ウィジェットが発行したトークンを siteverify
 * エンドポイントへ照会する（4.9.8「実装で確定した事項」）。
 *
 * ウィジェットの描画とトークンの発行はブラウザ側（Alpine）が担う。本サービスは
 * **サーバー側の検証のみ**を行い、ブラウザから届いたトークンは本メソッドの確認を
 * 経るまで信用しない（4.9.8「構成」）。
 *
 * **失敗はすべて `false` に倒す（fail closed）。** 検証の失敗・通信の失敗・
 * シークレットキー未設定のいずれも区別せず送信を通さない（4.9.8「失敗時の扱い」）。
 * 例外は投げない。トークン・IPアドレス・Cloudflare の応答はいずれもログへ
 * 出力しない（4.9.8「ログ」/ 17.9-1。`TmdbService` と同じ扱い）。
 */
class TurnstileService
{
    /**
     * 送信前の確認を提供できる状態か（15.1 の `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`）。
     *
     * サイトキーが無ければブラウザがウィジェットを描けず、シークレットキーが無ければ
     * サーバー側の検証ができない。片方だけでは送信が必ず失敗し、利用者の操作では
     * 解消しないため双方を見る（4.9.8「キー未設定時」。`StripeService::isConfigured()` と同じ扱い）。
     */
    public function isConfigured(): bool
    {
        return $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    /**
     * サイトキー。ブラウザへ渡してウィジェットの初期化に使う前提の値であり、
     * 秘匿情報ではない（17.9-1）。
     */
    public function siteKey(): string
    {
        $siteKey = config('services.turnstile.site_key');

        return is_string($siteKey) ? trim($siteKey) : '';
    }

    /**
     * トークンを siteverify へ照会する（4.9.2-4 / 4.9.8「検証の送信」）。
     *
     * シークレットキーが未設定、またはトークンが空の場合は外部を呼ばずに `false` を
     * 返す。通信に失敗した場合（`ConnectionException` 等）、2xx 以外の応答、
     * JSON でない応答もすべて `false` とする。
     *
     * **`hostname` は照合しない。** テスト用のシークレットキーはホスト名に関わらず
     * 成功を返すため（4.9.2-1）。本番用のキーへ切り替える場合は照合を加えること
     * （4.9.8「検証の送信」）。
     */
    public function verify(string $token, ?string $remoteIp): bool
    {
        $secretKey = $this->secretKey();

        // 呼び出し側（P-14）は `isConfigured()` を先に見るが、本メソッド単体でも
        // 設定漏れのまま通さない（fail closed）。
        if ($secretKey === '' || $token === '') {
            return false;
        }

        $parameters = [
            'secret' => $secretKey,
            'response' => $token,
        ];

        if ($remoteIp !== null) {
            $parameters['remoteip'] = $remoteIp;
        }

        /** @var string $verifyUrl */
        $verifyUrl = config('services.turnstile.verify_url');
        /** @var int $timeout */
        $timeout = config('services.turnstile.timeout');

        try {
            $response = Http::asForm()->timeout($timeout)->post($verifyUrl, $parameters);
        } catch (ConnectionException|GuzzleException) {
            // GuzzleException も捕捉するのは、リダイレクト上限超過等の例外が
            // 通信の失敗として扱われるべきものであるため（`TmdbService::send()` と同じ扱い）。
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        return $response->json('success') === true;
    }

    private function secretKey(): string
    {
        $secretKey = config('services.turnstile.secret_key');

        return is_string($secretKey) ? trim($secretKey) : '';
    }
}
