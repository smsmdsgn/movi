{{--
    予約照会（P-07、4.3.5 / 7.19）。照合の操作は Livewire コンポーネントが担い、
    本ビューは共通レイアウト・メタ情報・パンくずを組み立てる。

    **館非依存ページのため、パンくずに館を挟まない**（P-01 と館の間に位置しない。19.3-9）。
    インデックス対象外（19.3-6）のため BreadcrumbList の構造化データは付けない。

    館はビューで扱わない。ヘッダー（`x-front.header`）が `CurrentCinemaService` から
    自前で解決する。
--}}
<x-front.layout
    :title="__('front.lookup.title')"
    :description="__('front.lookup.description')"
    robots="noindex, nofollow"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => __('front.lookup.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.lookup.heading') }}</h1>
        <p class="mt-2 text-sm">{{ __('front.lookup.lead') }}</p>

        <div class="mt-6">
            <livewire:front.lookup.index />
        </div>
    </div>
</x-front.layout>
