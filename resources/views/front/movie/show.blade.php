{{--
    作品詳細（P-23、7.5）。TMDBから取得した情報と、選択中の館での上映期間・上映スケジュールを表示する。

    $cinema: Cinema
    $movie: Movie（formats 先読み済み）
    $bookings: Collection<Booking>（format 先読み済み、開始日順）
--}}
@php
    $canonicalUrl = route('front.movie.show', ['slug' => $cinema->slug, 'id' => $movie->id]);
    $cinemaTopUrl = route('front.cinema.show', ['slug' => $cinema->slug]);
    $posterUrl = $movie->posterUrl(\App\Services\TmdbService::POSTER_SIZE_DETAIL);

    $movieJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Movie',
        'name' => $movie->title,
        'description' => $movie->synopsis,
        'duration' => 'PT'.$movie->runtime_minutes.'M',
        'datePublished' => $movie->released_on->toDateString(),
        'url' => $canonicalUrl,
    ];

    if ($movie->original_title !== null) {
        $movieJsonLd['alternateName'] = $movie->original_title;
    }

    if ($posterUrl !== null) {
        $movieJsonLd['image'] = $posterUrl;
    }

    if (! empty($movie->genres)) {
        $movieJsonLd['genre'] = $movie->genres;
    }

    if ($movie->hasTmdbPage()) {
        $movieJsonLd['sameAs'] = $movie->tmdbUrl();
    }

    $jsonLd = [
        $movieJsonLd,
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
                    'name' => $movie->title,
                    'item' => $canonicalUrl,
                ],
            ],
        ],
    ];
@endphp
<x-front.layout
    :title="__('front.movie.title', ['movie' => $movie->title, 'cinema' => $cinema->name])"
    :description="\Illuminate\Support\Str::limit(__('front.movie.description', ['cinema' => $cinema->name, 'address' => $cinema->address, 'movie' => $movie->title, 'synopsis' => $movie->synopsis]), 120, '')"
    :canonical="$canonicalUrl"
    :ogImage="$posterUrl"
    ogType="video.movie"
    :jsonLd="$jsonLd"
>
    <div class="mx-auto max-w-5xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => $cinemaTopUrl],
            ['label' => $movie->title, 'url' => null],
        ]" />

        <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-[240px_1fr]">
            {{-- モバイル幅ではポスター（2:3）が画面幅いっぱいに縦長になるため上限幅を設ける。md 以上は左カラム（240px）に収まる。 --}}
            <div class="mx-auto w-full max-w-60 md:mx-0 md:max-w-none">
                <x-front.movie-poster :movie="$movie" :size="\App\Services\TmdbService::POSTER_SIZE_DETAIL" :eager="true" />
            </div>

            <div>
                <h1 class="text-2xl font-bold">{{ $movie->title }}</h1>

                @if ($movie->original_title !== null)
                    <p class="mt-1 text-sm text-stone-600">{{ $movie->original_title }}</p>
                @endif

                <dl class="mt-4 space-y-2 text-sm">
                    <div>
                        <dt class="font-bold text-stone-600">{{ __('front.movie.released_year') }}</dt>
                        <dd class="tabular-nums">{{ __('front.movie.released_year_value', ['year' => $movie->released_on->year]) }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-stone-600">{{ __('front.movie.runtime') }}</dt>
                        <dd class="tabular-nums">{{ __('front.movie.runtime_value', ['minutes' => $movie->runtime_minutes]) }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-stone-600">{{ __('front.movie.genres') }}</dt>
                        <dd>{{ ! empty($movie->genres) ? implode('・', $movie->genres) : __('front.movie.not_provided') }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-stone-600">{{ __('front.movie.formats') }}</dt>
                        <dd>
                            @if ($movie->formats->isEmpty())
                                {{ __('front.movie.not_provided') }}
                            @else
                                <span class="flex flex-wrap gap-1">
                                    @foreach ($movie->formats as $format)
                                        <span class="border border-stone-400 px-1 text-xs">{{ $format->name }}</span>
                                    @endforeach
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-bold text-stone-600">{{ __('front.movie.period', ['cinema' => $cinema->name]) }}</dt>
                        <dd>
                            <ul>
                                @foreach ($bookings as $booking)
                                    <li class="tabular-nums">
                                        {{ __('front.movie.period_value', ['format' => $booking->format->name, 'from' => $booking->starts_on->format('Y/n/j'), 'to' => $booking->ends_on->format('Y/n/j')]) }}
                                    </li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <section class="mt-8">
            <x-front.section-heading>{{ __('front.movie.synopsis') }}</x-front.section-heading>
            <p class="whitespace-pre-line p-4 text-sm">{{ $movie->synopsis }}</p>
        </section>

        <section class="mt-8">
            <x-front.section-heading>{{ __('front.movie.schedule', ['cinema' => $cinema->name]) }}</x-front.section-heading>
            <livewire:front.schedule.schedule-table :cinema="$cinema" :movie="$movie" />
        </section>

        @if ($movie->hasTmdbPage())
            <p class="mt-8 text-sm">
                <a href="{{ $movie->tmdbUrl() }}" target="_blank" rel="noopener noreferrer" class="font-bold text-brand underline">
                    {{ __('front.movie.tmdb_link') }}
                </a>
            </p>
        @endif
    </div>
</x-front.layout>
