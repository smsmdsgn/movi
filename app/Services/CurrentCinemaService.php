<?php

namespace App\Services;

use App\Models\Cinema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * 館非依存ページ・共通レイアウトが「現在の館」を得るためのサービス（13.2 Service命名規則）。
 *
 * `ResolveCinema` がコンテナへバインド済みの場合はそれを再利用し（13.4.1）、
 * 未バインドの場合はセッション → Cookie → 既定値（4.1.3-2）の順に解決してからバインドする。
 * これにより同一リクエスト内での再解決（二重クエリ）を防ぐ。
 *
 * 既定館（`Cinema::DEFAULT_SLUG`）自体が存在しない場合（A-03 でslugが変更された場合等）は
 * 任意の1件へフォールバックする。1件も存在しない場合のみ例外とする。
 * フォールバックが発生した場合はセッション・Cookieを解決結果で更新し、
 * 次回以降のリクエストで同じフォールバック検索を繰り返さないようにする。
 *
 * 注意: `app()->instance()` によるバインドはリクエスト境界で破棄されない。
 * 1つのテストメソッド内で複数回 `$this->get()` を呼ぶと、後続のリクエストが
 * 前回解決した館をセッションの中身に関係なく再利用してしまう（テストのみの制約。
 * 本番は PHP-FPM 等がリクエストごとに新しいプロセス／コンテナを使うため無害）。
 */
class CurrentCinemaService
{
    public function resolve(Request $request): Cinema
    {
        if (app()->bound(Cinema::class)) {
            return app(Cinema::class);
        }

        $slug = $request->session()->get(Cinema::SESSION_KEY) ?? $request->cookie(Cinema::SESSION_KEY);

        $cinema = (is_string($slug) ? Cinema::where('slug', $slug)->first() : null)
            ?? Cinema::where('slug', Cinema::DEFAULT_SLUG)->first()
            ?? Cinema::orderBy('id')->firstOrFail();

        app()->instance(Cinema::class, $cinema);

        if ($cinema->slug !== $slug) {
            $request->session()->put(Cinema::SESSION_KEY, $cinema->slug);
            Cookie::queue(Cinema::SESSION_KEY, $cinema->slug, 60 * 24 * 365);
        }

        return $cinema;
    }
}
