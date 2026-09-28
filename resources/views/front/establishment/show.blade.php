{{--
    施設案内（P-27、7.1.1 / 4.9.1）。館別ページはテンプレートを1枚のみ用意し、館マスタの
    内容を差し込む。館の性格を示す `concept` は館トップに加えて本ページにも表示する。

    $cinema: Cinema
--}}
@php
    $canonicalUrl = route('front.establishment.index', ['slug' => $cinema->slug]);
    $cinemaTopUrl = route('front.cinema.show', ['slug' => $cinema->slug]);
    $accessUrl = route('front.access.index', ['slug' => $cinema->slug]);
    // href に出す番号は数字のみに絞る（ハイフン・空白を含む表示用の値をそのまま tel: に使わない）。
    $telHref = preg_replace('/\D/', '', $cinema->phone);

    $jsonLd = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => __('front.breadcrumb.home'),
                    'item' => route('front.home'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $cinema->name,
                    'item' => $cinemaTopUrl,
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => __('front.establishment.heading'),
                    'item' => $canonicalUrl,
                ],
            ],
        ],
    ];
@endphp
<x-front.layout
    :title="__('front.establishment.title', ['cinema' => $cinema->name])"
    :description="__('front.establishment.description', ['cinema' => $cinema->name, 'address' => $cinema->address, 'concept' => $cinema->concept])"
    :canonical="$canonicalUrl"
    :jsonLd="$jsonLd"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => $cinemaTopUrl],
            ['label' => __('front.establishment.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.establishment.heading') }}</h1>
        <p class="mt-1 text-sm text-stone-600">{{ $cinema->name }}</p>

        <p class="mt-6 text-sm">{{ $cinema->concept }}</p>

        <dl class="mt-6 space-y-4 text-sm">
            <div>
                <dt class="font-bold text-stone-600">{{ __('front.establishment.business_hours') }}</dt>
                <dd>{{ $cinema->business_hours }}</dd>
            </div>
            <div>
                <dt class="font-bold text-stone-600">{{ __('front.establishment.phone') }}</dt>
                <dd>
                    {{-- 数字を含まない値（A-03 は形式を検証しない）では機能しない tel: リンクになるため、文字列のみ出す。 --}}
                    @if ($telHref !== '')
                        <a href="tel:{{ $telHref }}" class="underline decoration-stone-400">{{ $cinema->phone }}</a>
                    @else
                        {{ $cinema->phone }}
                    @endif
                </dd>
            </div>
        </dl>

        <section class="mt-8">
            <x-front.section-heading>{{ __('front.establishment.facility_info_heading') }}</x-front.section-heading>
            <p class="whitespace-pre-line p-4 text-sm">{{ $cinema->facility_info }}</p>
        </section>

        <p class="mt-8 text-sm">
            <a href="{{ $accessUrl }}" class="font-bold text-brand underline">{{ __('front.establishment.to_access') }}</a>
        </p>
    </div>
</x-front.layout>
