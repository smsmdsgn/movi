{{--
    上映スケジュール（P-22、7.4）。館トップにも掲載する上映スケジュール表を単独ページで表示する。

    $cinema: Cinema
--}}
@php
    $canonicalUrl = route('front.schedule.index', ['slug' => $cinema->slug]);
    $cinemaTopUrl = route('front.cinema.show', ['slug' => $cinema->slug]);
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
                    'name' => __('front.schedule.heading'),
                    'item' => $canonicalUrl,
                ],
            ],
        ],
    ];
@endphp
<x-front.layout
    :title="__('front.schedule.title', ['cinema' => $cinema->name])"
    :description="\Illuminate\Support\Str::limit(__('front.schedule.description', ['cinema' => $cinema->name, 'address' => $cinema->address]), 120, '')"
    :canonical="$canonicalUrl"
    :jsonLd="$jsonLd"
>
    <div class="mx-auto max-w-5xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => $cinemaTopUrl],
            ['label' => __('front.schedule.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.schedule.heading') }}</h1>

        <div class="mt-4">
            {{-- h1 直下に置くため、作品ブロックの見出しを h2 にして階層を飛ばさない（19.3-7）。 --}}
            <livewire:front.schedule.schedule-table :cinema="$cinema" heading-level="h2" />
        </div>
    </div>
</x-front.layout>
