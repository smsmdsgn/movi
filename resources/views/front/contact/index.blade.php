{{--
    お問い合わせ（P-14、4.9.2。工程7-g）。入力の受付は Livewire コンポーネントが担い、
    本ビューは共通の枠（`x-front.page`）と冒頭の案内のみを組み立てる
    （`resources/views/front/lookup/index.blade.php` と同じ分担）。

    館はビューで扱わない。ヘッダー（`x-front.header`）が `CurrentCinemaService` から
    自前で解決する。
--}}
<x-front.page
    :title="__('front.contact.title')"
    :heading="__('front.contact.heading')"
    :description="__('front.contact.description')"
>
    {{-- Turnstile のウィジェット（4.9.2「ボット対策」/ 4.9.8）は Cloudflare の配信元から
         読み込む（17.7 のCSP `script-src`）。P-36 の Stripe.js（4.3.14）と同じ構成で、
         npm で同梱すると自サイトのコードとして扱われてしまう。`render=explicit` を指定し、
         Alpine の x-init から `turnstile.render()` を呼んで描画する。キーが揃っていない
         場合はウィジェットを描かないため読み込まない（4.9.8「キー未設定時」）。 --}}
    @if ($isTurnstileEnabled)
        @push('head')
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" defer></script>
        @endpush
    @endif

    <p>{{ __('front.contact.lead') }}</p>

    <p class="border border-stone-300 bg-stone-50 p-3 text-sm">
        {{ __('front.contact.dummy_notice') }}
    </p>

    <p class="text-sm">
        {{ __('front.contact.recruit_notice') }}
        <a href="{{ route('front.recruit.index') }}" class="underline decoration-stone-400 hover:no-underline">
            {{ __('front.contact.recruit_link') }}
        </a>
    </p>

    <livewire:front.contact.index />
</x-front.page>
