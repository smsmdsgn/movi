{{--
    お知らせ詳細（P-26、7.1.1 / 4.7.1）。

    $cinema: Cinema
    $post: Post（category 先読み済み）
    $body: HtmlString（PostBodyService::render() 済みの本文。サニタイズ済みのため
        `{{ }}` のまま出力する。`{!! !!}` は使わない、17.5.2-1）
    $excerpt: string（PostBodyService::plainText() 済みの本文。meta description 用）
--}}
@php
    $canonicalUrl = route('front.news.show', ['slug' => $cinema->slug, 'id' => $post->id]);
    $cinemaTopUrl = route('front.cinema.show', ['slug' => $cinema->slug]);
    $newsIndexUrl = route('front.news.index', ['slug' => $cinema->slug]);
    $categoryUrl = route('front.news.category', ['slug' => $cinema->slug, 'category' => $post->category->slug]);

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
                    'name' => __('front.news.heading'),
                    'item' => $newsIndexUrl,
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 4,
                    'name' => $post->title,
                    'item' => $canonicalUrl,
                ],
            ],
        ],
    ];
@endphp
<x-front.layout
    :title="__('front.news.detail.title', ['title' => $post->title, 'cinema' => $cinema->name])"
    :description="\Illuminate\Support\Str::limit(__('front.news.detail.description', ['cinema' => $cinema->name, 'address' => $cinema->address, 'excerpt' => $excerpt]), 120, '')"
    :canonical="$canonicalUrl"
    ogType="article"
    :jsonLd="$jsonLd"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => $cinemaTopUrl],
            ['label' => __('front.news.heading'), 'url' => $newsIndexUrl],
            ['label' => $post->title, 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ $post->title }}</h1>

        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-stone-600">
            <time datetime="{{ $post->published_at->format('Y-m-d') }}" class="tabular-nums">{{ $post->published_at->format('Y/n/j') }}</time>
            <x-front.post-category-label :category="$post->category" :href="$categoryUrl" />
        </div>

        {{-- typography プラグインは未導入のため、任意バリアントで本文の各要素を整える（13.5-2）。 --}}
        <div class="mt-6 text-sm leading-relaxed [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-bold [&_h3]:mt-5 [&_h3]:text-lg [&_h3]:font-bold [&_h4]:mt-4 [&_h4]:text-base [&_h4]:font-bold [&_h5]:mt-4 [&_h5]:text-base [&_h5]:font-bold [&_h6]:mt-4 [&_h6]:text-sm [&_h6]:font-bold [&_p]:mt-4 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:pl-6 [&_li]:mt-1 [&_a]:text-brand [&_a]:underline [&_strong]:font-bold [&_em]:italic [&_img]:mt-4 [&_img]:max-w-full">
            {{ $body }}
        </div>

        <p class="mt-8 text-sm">
            <a href="{{ $newsIndexUrl }}" class="font-bold text-brand underline">{{ __('front.news.back_to_list') }}</a>
        </p>
    </div>
</x-front.layout>
