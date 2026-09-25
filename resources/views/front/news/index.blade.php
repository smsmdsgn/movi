{{--
    お知らせ一覧（P-24）・カテゴリー別（P-25、7.1.1 / 4.7.1）。同一のビューを共用する。

    $cinema: Cinema
    $category: PostCategory|null（P-25 のときのみ）
    $categories: Collection<PostCategory>（id昇順、カテゴリー切替ナビ用）
    $posts: LengthAwarePaginator<Post>（category 先読み済み、published_at 降順→id降順）
--}}
@php
    $isCategoryPage = $category !== null;
    $newsIndexUrl = route('front.news.index', ['slug' => $cinema->slug]);
    $cinemaTopUrl = route('front.cinema.show', ['slug' => $cinema->slug]);
    $baseUrl = $isCategoryPage
        ? route('front.news.category', ['slug' => $cinema->slug, 'category' => $category->slug])
        : $newsIndexUrl;
    /* 正規URLは slug（小文字）から組み立てる。`$posts->url()` はリクエストのパスを引き継ぐため、`/news/CAMPAIGN` の大文字が残る。 */
    $canonicalUrl = $posts->currentPage() > 1 ? $baseUrl.'?page='.$posts->currentPage() : $baseUrl;

    $pageTitle = $isCategoryPage
        ? __('front.news.category_title', ['category' => $category->name, 'cinema' => $cinema->name])
        : __('front.news.title', ['cinema' => $cinema->name]);

    $pageDescription = $isCategoryPage
        ? __('front.news.category_description', ['cinema' => $cinema->name, 'address' => $cinema->address, 'category' => $category->name])
        : __('front.news.description', ['cinema' => $cinema->name, 'address' => $cinema->address]);

    $breadcrumbItems = [
        ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
        ['label' => $cinema->name, 'url' => $cinemaTopUrl],
    ];

    if ($isCategoryPage) {
        $breadcrumbItems[] = ['label' => __('front.news.heading'), 'url' => $newsIndexUrl];
        $breadcrumbItems[] = ['label' => $category->name, 'url' => null];
    } else {
        $breadcrumbItems[] = ['label' => __('front.news.heading'), 'url' => null];
    }

    $jsonLd = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($breadcrumbItems)->values()->map(fn ($item, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['label'],
                'item' => $item['url'] ?? $canonicalUrl,
            ])->all(),
        ],
    ];
@endphp
<x-front.layout
    :title="$pageTitle"
    :description="\Illuminate\Support\Str::limit($pageDescription, 120, '')"
    :canonical="$canonicalUrl"
    :jsonLd="$jsonLd"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="$breadcrumbItems" />

        <h1 class="mt-4 text-2xl font-bold">{{ $isCategoryPage ? $category->name : __('front.news.heading') }}</h1>

        <nav aria-label="{{ __('front.news.category_nav_label') }}" class="mt-4">
            <ul class="flex flex-wrap gap-2 text-sm">
                <li>
                    @if ($isCategoryPage)
                        <a href="{{ $newsIndexUrl }}" class="inline-block border border-stone-400 px-3 py-1 underline decoration-stone-400 hover:bg-stone-100">{{ __('front.news.all_categories') }}</a>
                    @else
                        {{-- 現在地は色のみで示さず aria-current でも伝える（13.5-5）。 --}}
                        <span aria-current="page" class="inline-block border-2 border-red-800 bg-red-50 px-3 py-1 font-bold text-red-900">{{ __('front.news.all_categories') }}</span>
                    @endif
                </li>
                @foreach ($categories as $navCategory)
                    <li>
                        @if ($isCategoryPage && $category->is($navCategory))
                            <span aria-current="page" class="inline-block border-2 border-red-800 bg-red-50 px-3 py-1 font-bold text-red-900">{{ $navCategory->name }}</span>
                        @else
                            <a href="{{ route('front.news.category', ['slug' => $cinema->slug, 'category' => $navCategory->slug]) }}" class="inline-block border border-stone-400 px-3 py-1 underline decoration-stone-400 hover:bg-stone-100">{{ $navCategory->name }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        @if ($posts->isEmpty())
            <p class="mt-8 text-sm text-stone-600">{{ __('front.news.empty') }}</p>
        @else
            <ul class="mt-6 divide-y divide-stone-300">
                @foreach ($posts as $post)
                    <li class="py-4">
                        <a href="{{ route('front.news.show', ['slug' => $cinema->slug, 'id' => $post->id]) }}" class="flex flex-col gap-1 md:flex-row md:items-baseline md:gap-3">
                            <time datetime="{{ $post->published_at->format('Y-m-d') }}" class="shrink-0 text-sm tabular-nums text-stone-600">{{ $post->published_at->format('Y/n/j') }}</time>
                            <x-front.post-category-label :category="$post->category" />
                            <span class="font-bold underline decoration-stone-400">{{ $post->title }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <x-front.pagination :paginator="$posts" />
        @endif
    </div>
</x-front.layout>
