<?php

namespace App\Livewire\Front\CookieConsent;

use App\Enums\CookieConsent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Cookie同意ダイアログ（4.9.3 / 4.9.7）。顧客向け共通レイアウト
 * （`components/front/layout.blade.php`）の `<body>` 直下・末尾に置く。
 * 選択（同意する／拒否する）により状態が変わるため Livewire コンポーネントとする（13.4.3）。
 *
 * **選択済みの利用者にはレイアウト側がコンポーネントごと出力しない**（4.9.7「構成」）ため、
 * 本コンポーネントが描画される時点では常に未選択の状態から始まる。
 */
class Dialog extends Component
{
    /**
     * 選択を終えたか。選択直後、同一リクエスト内でダイアログの中身を消すためだけに使う。
     * クライアントから差し替えられないよう `Locked` とする。
     */
    #[Locked]
    public bool $isDecided = false;

    /** 「同意する」を選択（4.9.3-2）。 */
    public function accept(): void
    {
        $this->decide(CookieConsent::Accepted);
    }

    /** 「拒否する」を選択（4.9.3-2）。 */
    public function reject(): void
    {
        $this->decide(CookieConsent::Rejected);
    }

    /**
     * 選択結果を Cookie へ保存し、ダイアログの中身を消す（4.9.7「保存する Cookie」）。
     * 暗号化・`HttpOnly`・`SameSite` は Laravel 既定（`config/session.php`）のまま適用する。
     */
    private function decide(CookieConsent $consent): void
    {
        Cookie::queue(CookieConsent::COOKIE_NAME, $consent->value, CookieConsent::LIFETIME_MINUTES);

        $this->isDecided = true;
    }

    public function render(): View
    {
        return view('front.cookie-consent.dialog');
    }
}
