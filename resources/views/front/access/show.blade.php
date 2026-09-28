{{--
    アクセス（P-28、7.1.1 / 4.9.1）。館別ページはテンプレートを1枚のみ用意し、館マスタの
    `address`・`access_note`・地図（`map_embed_url`）を差し込む。

    $cinema: Cinema
--}}
@php
    $canonicalUrl = route('front.access.index', ['slug' => $cinema->slug]);
    $cinemaTopUrl = route('front.cinema.show', ['slug' => $cinema->slug]);
    $establishmentUrl = route('front.establishment.index', ['slug' => $cinema->slug]);
    // iframe の src には必ずこの安全なURLを使う。`map_embed_url` を直接出さない（17.7）。
    $mapEmbedUrl = $cinema->safeMapEmbedUrl();

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
                    'name' => __('front.access.heading'),
                    'item' => $canonicalUrl,
                ],
            ],
        ],
    ];
@endphp
<x-front.layout
    :title="__('front.access.title', ['cinema' => $cinema->name])"
    :description="__('front.access.description', ['cinema' => $cinema->name, 'address' => $cinema->address])"
    :canonical="$canonicalUrl"
    :jsonLd="$jsonLd"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => $cinemaTopUrl],
            ['label' => __('front.access.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.access.heading') }}</h1>
        <p class="mt-1 text-sm text-stone-600">{{ $cinema->name }}</p>

        <dl class="mt-6 space-y-4 text-sm">
            <div>
                <dt class="font-bold text-stone-600">{{ __('front.access.address_heading') }}</dt>
                <dd>{{ $cinema->address }}</dd>
            </div>
        </dl>

        <section class="mt-6">
            <x-front.section-heading>{{ __('front.access.note_heading') }}</x-front.section-heading>
            <p class="whitespace-pre-line p-4 text-sm">{{ $cinema->access_note }}</p>
        </section>

        @if ($mapEmbedUrl !== null)
            <section class="mt-8">
                <x-front.section-heading>{{ __('front.access.map_heading') }}</x-front.section-heading>
                <div class="aspect-video w-full">
                    <iframe
                        src="{{ $mapEmbedUrl }}"
                        title="{{ __('front.access.map_title', ['cinema' => $cinema->name]) }}"
                        loading="lazy"
                        class="h-full w-full border-0"
                    ></iframe>
                </div>
            </section>
        @endif

        <p class="mt-8 text-sm">
            <a href="{{ $establishmentUrl }}" class="font-bold text-brand underline">{{ __('front.access.to_establishment') }}</a>
        </p>
    </div>
</x-front.layout>
