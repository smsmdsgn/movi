{{--
    館トップ（P-21、7.3）。作品一覧タブと上映スケジュール表を表示する。

    構成要素のうちメインバナー・カルーセル・小バナー・重要なお知らせ・お知らせ／キャンペーン・
    各種バナーリンク（7.3-2・4〜7・9）は工程7（お知らせ・バナー）で追加する。

    $cinema: Cinema
    $listings: MovieListingCategory の値をキーとする Collection<MovieListing> の配列
--}}
@php
    $canonicalUrl = route('front.cinema.show', ['slug' => $cinema->slug]);
    $jsonLd = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'MovieTheater',
            'name' => $cinema->name,
            'description' => $cinema->concept,
            'address' => $cinema->address,
            'telephone' => $cinema->phone,
            'url' => $canonicalUrl,
        ],
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
                    'item' => $canonicalUrl,
                ],
            ],
        ],
    ];
@endphp
<x-front.layout
    :title="__('front.cinema_top.title', ['cinema' => $cinema->name])"
    :description="\Illuminate\Support\Str::limit(__('front.cinema_top.description', ['cinema' => $cinema->name, 'address' => $cinema->address, 'concept' => $cinema->concept]), 120, '')"
    :canonical="$canonicalUrl"
    :jsonLd="$jsonLd"
>
    <div class="mx-auto max-w-5xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ $cinema->name }}</h1>
        <p class="mt-1 text-sm text-stone-600">{{ $cinema->concept }}</p>

        {{-- 7.3-2・4〜7・9（メインバナー・カルーセル・小バナー・重要なお知らせ・お知らせ／キャンペーン・各種バナーリンク）は工程7で追加する。 --}}

        @php
            $categories = \App\Enums\MovieListingCategory::cases();
            $defaultCategory = \App\Enums\MovieListingCategory::Now;
        @endphp
        <section x-data="{ tab: '{{ $defaultCategory->value }}' }" class="mt-8">
            <x-front.section-heading>{{ __('front.cinema_top.movies_heading') }}</x-front.section-heading>

            <div role="tablist" class="flex border-b border-stone-300">
                @foreach ($categories as $category)
                    <button
                        type="button"
                        role="tab"
                        id="movie-tab-{{ $category->value }}"
                        aria-controls="movie-panel-{{ $category->value }}"
                        x-on:click="tab = '{{ $category->value }}'"
                        :aria-selected="tab === '{{ $category->value }}'"
                        :tabindex="tab === '{{ $category->value }}' ? 0 : -1"
                        :class="tab === '{{ $category->value }}' ? 'border-b-2 border-brand font-bold' : 'text-stone-600'"
                        class="px-4 py-2 text-sm"
                    >
                        {{ __($category->labelKey()) }}
                    </button>
                @endforeach
            </div>

            {{-- 既定タブ（上映中）には x-cloak を付けず、Alpine が動かない環境でも作品一覧が見えるようにする。 --}}
            @foreach ($categories as $category)
                <div
                    role="tabpanel"
                    id="movie-panel-{{ $category->value }}"
                    aria-labelledby="movie-tab-{{ $category->value }}"
                    x-show="tab === '{{ $category->value }}'"
                    @if ($category !== $defaultCategory) x-cloak @endif
                    data-tab="{{ $category->value }}"
                    class="py-4"
                >
                    @if ($listings[$category->value]->isEmpty())
                        <p class="text-sm text-stone-600">{{ __($category->emptyKey()) }}</p>
                    @else
                        <ul class="grid grid-cols-2 gap-4 md:grid-cols-4">
                            @foreach ($listings[$category->value] as $listing)
                                <li>
                                    <x-front.movie-poster :movie="$listing->movie" />
                                    <p class="mt-2 font-bold">
                                        <a href="{{ route('front.movie.show', ['slug' => $cinema->slug, 'id' => $listing->movie->id]) }}">
                                            {{ $listing->movie->title }}
                                        </a>
                                    </p>
                                    <p class="mt-1 flex flex-wrap gap-1 text-xs">
                                        @foreach ($listing->formats as $format)
                                            <span class="border border-stone-400 px-1">{{ $format->name }}</span>
                                        @endforeach
                                    </p>
                                    <p class="mt-1 text-xs text-stone-600 tabular-nums">
                                        {{ __('front.cinema_top.period', ['from' => $listing->startsOn->format('Y/n/j'), 'to' => $listing->endsOn->format('Y/n/j')]) }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </section>

        <section class="mt-8">
            <x-front.section-heading>{{ __('front.cinema_top.schedule_heading') }}</x-front.section-heading>
            <livewire:front.schedule.schedule-table :cinema="$cinema" />
            <a href="{{ route('front.schedule.index', ['slug' => $cinema->slug]) }}" class="mt-4 inline-block text-sm font-bold text-brand underline">
                {{ __('front.cinema_top.view_schedule') }}
            </a>
        </section>
    </div>
</x-front.layout>
