{{--
    館非依存の静的ページ（P-08〜P-13, P-16〜P-20、4.9.1 / 7.1.1。工程7-f）共通の枠。

    館はビューで扱わない。ヘッダー（`x-front.header`）が `CurrentCinemaService` から
    自前で解決する（P-07 と同じ。`resources/views/front/lookup/index.blade.php` 参照）。

    **館非依存ページのため、パンくずに館を挟まない**（P-01 と館の間に位置しない。19.3-9）。
    館配下のページではないため `BreadcrumbList` の構造化データは付けない。

    props:
    - title: 19.2 の形式（`{ページ名}｜MOVI`）で呼び出し側が組み立てる
    - heading: h1 に出す見出し。パンくずの現在地にも使う
    - description: 120文字程度の要約。省略時はレイアウトの既定文言
--}}
@props([
    'title',
    'heading',
    'description' => null,
])
<x-front.layout :title="$title" :description="$description">
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $heading, 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ $heading }}</h1>

        <div class="mt-6 space-y-8 text-sm">
            {{ $slot }}
        </div>
    </div>
</x-front.layout>
