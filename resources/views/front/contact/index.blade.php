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
