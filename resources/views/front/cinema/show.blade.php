{{--
    館トップ（P-21、7.3）。作品一覧タブ・上映スケジュール表・バナー・お知らせを表示する
    （工程7-d、7.3-2・4〜7・9）。

    $cinema: Cinema
    $listings: MovieListingCategory の値をキーとする Collection<MovieListing> の配列
    $mainBanner: Banner|null（掲載位置 main の先頭1枚）
    $carouselBanners: Collection<Banner>（掲載位置 carousel）
    $subBanners: Collection<Banner>（掲載位置 sub、先頭2枚まで）
    $footerBanners: Collection<Banner>（掲載位置 footer_link）
    $importantSection: array{category: PostCategory, posts: Collection<Post>}|null（重要なお知らせの最新5件。記事が無ければ null）
    $newsSections: Collection<array{category: PostCategory, posts: Collection<Post>}>（notice・campaign）
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

        {{-- メインバナー（7.3-2）。ファーストビューのため eager 読み込みとする（13.5-6）。 --}}
        @if ($mainBanner !== null)
            <div class="mt-6">
                <x-front.banner :banner="$mainBanner" :eager="true" />
            </div>
        @endif

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

        {{-- カルーセルスライダー（7.3-4）。リンク先は任意（バナーのリンクURLに従う）。自動送りはしない。 --}}
        @if ($carouselBanners->isNotEmpty())
            @php $carouselCount = $carouselBanners->count(); @endphp
            <section
                x-data="{ current: 0, count: {{ $carouselCount }} }"
                class="relative mt-8"
                aria-roledescription="carousel"
                aria-label="{{ __('front.cinema_top.carousel.label') }}"
            >
                @foreach ($carouselBanners as $index => $banner)
                    <div
                        role="group"
                        aria-roledescription="slide"
                        aria-label="{{ __('front.cinema_top.carousel.slide_label', ['current' => $index + 1, 'total' => $carouselCount]) }}"
                        x-show="current === {{ $index }}"
                        @if ($index !== 0) x-cloak @endif
                    >
                        <x-front.banner :banner="$banner" />
                    </div>
                @endforeach

                @if ($carouselCount > 1)
                    <button
                        type="button"
                        x-on:click="current = (current - 1 + count) % count"
                        aria-label="{{ __('front.cinema_top.carousel.prev') }}"
                        class="absolute top-1/2 left-2 -translate-y-1/2 flex min-h-6 min-w-6 items-center justify-center border border-stone-400 bg-white/80 px-2 py-1 text-sm"
                    >‹</button>
                    <button
                        type="button"
                        x-on:click="current = (current + 1) % count"
                        aria-label="{{ __('front.cinema_top.carousel.next') }}"
                        class="absolute top-1/2 right-2 -translate-y-1/2 flex min-h-6 min-w-6 items-center justify-center border border-stone-400 bg-white/80 px-2 py-1 text-sm"
                    >›</button>

                    <div class="mt-2 flex justify-center gap-2">
                        @foreach ($carouselBanners as $index => $banner)
                            <button
                                type="button"
                                x-on:click="current = {{ $index }}"
                                x-bind:aria-current="current === {{ $index }} ? 'true' : null"
                                aria-label="{{ __('front.cinema_top.carousel.goto_slide', ['number' => $index + 1]) }}"
                                class="flex h-6 w-6 items-center justify-center"
                            >
                                {{-- 押せる範囲は 24px 四方を確保し（WCAG 2.5.8）、見た目の丸は内側に置く。 --}}
                                <span class="block h-2.5 w-2.5 rounded-full border border-stone-500" :class="current === {{ $index }} ? 'bg-brand' : 'bg-white'"></span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        {{-- 小バナー × 2（7.3-5） --}}
        @if ($subBanners->isNotEmpty())
            <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($subBanners as $banner)
                    <x-front.banner :banner="$banner" />
                @endforeach
            </div>
        @endif

        {{-- 重要なお知らせ（7.3-6）。該当記事がある場合のみ表示する。色だけに頼らず、
             強調枠と「重要なお知らせ」というカテゴリー名自体で示す（13.5-5）。 --}}
        @if ($importantSection !== null)
            @php
                $importantCategory = $importantSection['category'];
                $importantPosts = $importantSection['posts'];
            @endphp
            <section class="mt-8 border-2 border-red-800 bg-red-50 p-4">
                <h2 class="text-lg font-bold text-red-900">{{ $importantCategory->name }}</h2>
                <ul class="mt-3 divide-y divide-red-200">
                    @foreach ($importantPosts as $post)
                        <li class="py-2">
                            <a href="{{ route('front.news.show', ['slug' => $cinema->slug, 'id' => $post->id]) }}" class="flex flex-col gap-1 md:flex-row md:items-baseline md:gap-3">
                                <time datetime="{{ $post->published_at->format('Y-m-d') }}" class="shrink-0 text-sm tabular-nums text-red-900">{{ $post->published_at->format('Y/n/j') }}</time>
                                <span class="font-bold underline decoration-red-400">{{ $post->title }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('front.news.category', ['slug' => $cinema->slug, 'category' => $importantCategory->slug]) }}" class="mt-3 inline-block text-sm font-bold text-red-900 underline">
                    {{ __('front.cinema_top.news.archive_link', ['category' => $importantCategory->name]) }}
                </a>
            </section>
        @endif

        {{-- お知らせ／キャンペーン（7.3-7）。各最新5件。カテゴリーの行が無い場合はその区画を出さない。 --}}
        @if ($newsSections->isNotEmpty())
            <section class="mt-8 grid grid-cols-1 gap-6 md:grid-cols-2">
                @foreach ($newsSections as $section)
                    @php
                        $newsCategory = $section['category'];
                        $newsPosts = $section['posts'];
                    @endphp
                    <div>
                        <x-front.section-heading>{{ $newsCategory->name }}</x-front.section-heading>
                        @if ($newsPosts->isEmpty())
                            <p class="mt-3 text-sm text-stone-600">{{ __('front.cinema_top.news.empty', ['category' => $newsCategory->name]) }}</p>
                        @else
                            <ul class="mt-3 divide-y divide-stone-300">
                                @foreach ($newsPosts as $post)
                                    <li class="py-2">
                                        <a href="{{ route('front.news.show', ['slug' => $cinema->slug, 'id' => $post->id]) }}" class="flex flex-col gap-1 md:flex-row md:items-baseline md:gap-3">
                                            <time datetime="{{ $post->published_at->format('Y-m-d') }}" class="shrink-0 text-sm tabular-nums text-stone-600">{{ $post->published_at->format('Y/n/j') }}</time>
                                            <span class="font-bold underline decoration-stone-400">{{ $post->title }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <a href="{{ route('front.news.category', ['slug' => $cinema->slug, 'category' => $newsCategory->slug]) }}" class="mt-3 inline-block text-sm font-bold text-brand underline">
                            {{ __('front.cinema_top.news.archive_link', ['category' => $newsCategory->name]) }}
                        </a>
                    </div>
                @endforeach
            </section>
        @endif

        <section class="mt-8">
            <x-front.section-heading>{{ __('front.cinema_top.schedule_heading') }}</x-front.section-heading>
            <livewire:front.schedule.schedule-table :cinema="$cinema" />
            <a href="{{ route('front.schedule.index', ['slug' => $cinema->slug]) }}" class="mt-4 inline-block text-sm font-bold text-brand underline">
                {{ __('front.cinema_top.view_schedule') }}
            </a>
        </section>

        {{-- 各種バナーリンク（7.3-9） --}}
        @if ($footerBanners->isNotEmpty())
            <nav aria-label="{{ __('front.cinema_top.footer_banners.label') }}" class="mt-8">
                <ul class="flex flex-wrap gap-4">
                    @foreach ($footerBanners as $banner)
                        <li class="w-56">
                            <x-front.banner :banner="$banner" />
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
</x-front.layout>
