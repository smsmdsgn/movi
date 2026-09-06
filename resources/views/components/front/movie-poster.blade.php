{{--
    作品ポスター（7.4-6 / 7.5-1 / 13.5-6）。

    props:
    - movie: Movie
    - size: TMDB の画像サイズ（`App\Services\TmdbService::POSTER_SIZE_*`）
    - eager: true の場合 `loading="lazy"` を付けない（ファーストビュー用、13.5-6）

    シーダーの架空作品は `poster_path` を持たないため `posterUrl()` が null になる。
    その場合は2:3の枠に作品タイトルを小さく表示する代替ブロックを出す。
--}}
@props([
    'movie',
    'size' => \App\Services\TmdbService::POSTER_SIZE_THUMBNAIL,
    'eager' => false,
])
@php
    $posterUrl = $movie->posterUrl($size);
@endphp
@if ($posterUrl)
    <img
        src="{{ $posterUrl }}"
        alt="{{ __('front.movie.poster_alt', ['movie' => $movie->title]) }}"
        class="aspect-[2/3] w-full object-cover"
        @if (! $eager) loading="lazy" @endif
    >
@else
    <div class="flex aspect-[2/3] w-full items-center justify-center bg-stone-200 p-2 text-center">
        <span class="sr-only">{{ __('front.movie.no_poster') }}</span>
        <span class="text-xs text-stone-500">{{ $movie->title }}</span>
    </div>
@endif
