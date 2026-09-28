<?php

namespace App\Enums;

use Illuminate\Http\Request;

/**
 * Cookie同意ダイアログ（4.9.3 / 4.9.7）の選択結果。
 * `App\Livewire\Front\CookieConsent\Dialog` が発行する `cookie_consent` Cookie の値。
 */
enum CookieConsent: string
{
    /** 同意する */
    case Accepted = 'accepted';

    /** 拒否する */
    case Rejected = 'rejected';

    /** 保存する Cookie の名称（4.9.7「保存する Cookie」）。 */
    public const string COOKIE_NAME = 'cookie_consent';

    /**
     * Cookie の有効期間（分）。1年。選択中の館（`cinema_slug`）の保存期間と揃える
     * （4.9.7「保存する Cookie」）。
     */
    public const int LIFETIME_MINUTES = 60 * 24 * 365;

    /**
     * リクエストの Cookie から選択結果を得る。未設定、または本 enum に変換できない
     * 値（未知の値）は未選択として `null` を返す（4.9.7「未知の値」）。
     */
    public static function fromRequest(Request $request): ?self
    {
        $value = $request->cookie(self::COOKIE_NAME);

        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * 計測タグ（`@stack('tracking')`）の出力を許すか。`Accepted` のときのみ true とし、
     * 未選択・拒否を同意とみなさない（オプトイン。4.9.7「計測タグの分岐」）。
     */
    public function allowsTracking(): bool
    {
        return $this === self::Accepted;
    }
}
