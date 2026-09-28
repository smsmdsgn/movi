{{--
    Cookie同意ダイアログ（4.9.3 / 4.9.7）。`App\Livewire\Front\CookieConsent\Dialog` のビュー。
    顧客向け共通レイアウト（`components/front/layout.blade.php`）の <body> 直下・末尾に置く。

    選択（同意する／拒否する）を終えると $isDecided が true になり、同一リクエスト内で
    中身を消す。以降のページ読み込みではレイアウト側が Cookie の有無でコンポーネントごと
    出し分ける（選択済みの利用者には本コンポーネント自体を出力しない）ため、ここで扱うのは
    常に「これから選択する」状態のみである。

    ルート要素を sticky にするため、ルートは1つの <div> のみとする（内側の要素を sticky
    にしても親要素の高さの分しか動けず効かない。4.9.7「表示位置」）。印刷時は出さない。

    背後の操作を妨げず、フォーカスも移さない帯であるため、`role="dialog"` ではなく
    見出しで名前を付けた region（`<section aria-labelledby>`）とする（4.9.7「表示位置」）。
--}}
<div class="sticky bottom-0 z-40 print:hidden">
    @unless ($isDecided)
        <section
            aria-labelledby="cookie-consent-heading"
            class="border-t border-white/20 bg-brand text-white"
        >
            <div class="mx-auto flex max-w-5xl flex-col gap-4 px-4 py-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 id="cookie-consent-heading" class="font-bold">
                        {{ __('front.cookie_consent.heading') }}
                    </h2>
                    <p class="mt-1 text-sm">
                        {{ __('front.cookie_consent.body') }}
                        <a href="{{ route('front.cookie-policy.index') }}" class="underline">
                            {{ __('front.cookie_consent.policy_link') }}
                        </a>
                    </p>
                </div>

                {{-- 「同意する」「拒否する」は同じ大きさ・同じ強さの見た目にする（どちらへも誘導しない。4.9.7「ボタン」）。 --}}
                <div class="flex shrink-0 gap-3">
                    <button
                        type="button"
                        wire:click="accept"
                        class="border border-white px-5 py-2 text-sm font-bold hover:bg-white hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    >
                        {{ __('front.cookie_consent.accept') }}
                    </button>

                    <button
                        type="button"
                        wire:click="reject"
                        class="border border-white px-5 py-2 text-sm font-bold hover:bg-white hover:text-brand focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    >
                        {{ __('front.cookie_consent.reject') }}
                    </button>
                </div>
            </div>
        </section>
    @endunless
</div>
