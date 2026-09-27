{{--
    サイトマップ（P-20、7.1.1 / 4.9.1。工程7-f）。

    館非依存ページ（P-01・P-07〜P-14・P-16〜P-19）と、館ごと（P-21・P-22・P-24・P-27・P-28）のリンクを
    一覧する。マイページ（会員専用）と予約フロー（上映回IDに依存し、時間の経過とともに
    存在しなくなる。19.3-6）は載せない。**P-15（お問い合わせ送信完了）も、フォーム送信
    後にのみ到達する完了画面であり直接の入口ではないため、P-38（予約完了）と同様に載せない。**

    見出しラベルは各ページ自身の `heading`／`title` を再利用し、本ページ専用の
    重複した文言を持たない。

    $cinemas: Collection<Cinema>（id 順）
--}}
<x-front.page
    :title="__('front.pages.sitemap.title')"
    :heading="__('front.pages.sitemap.heading')"
    :description="__('front.pages.sitemap.description')"
>
    <section>
        <x-front.section-heading>{{ __('front.pages.sitemap.common_heading') }}</x-front.section-heading>

        <ul class="mt-4 list-inside list-disc space-y-1">
            <li><a href="{{ route('front.home') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.sitemap.home_label') }}</a></li>
            <li><a href="{{ route('front.lookup.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.lookup.heading') }}</a></li>
            <li><a href="{{ route('front.prices.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.prices.heading') }}</a></li>
            <li><a href="{{ route('front.food.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.food.heading') }}</a></li>
            <li><a href="{{ route('front.presale.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.presale.heading') }}</a></li>
            <li><a href="{{ route('front.faq.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.faq.heading') }}</a></li>
            <li><a href="{{ route('front.contact.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.footer.contact') }}</a></li>
            <li><a href="{{ route('front.recruit.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.recruit.heading') }}</a></li>
            <li><a href="{{ route('front.company.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.company.heading') }}</a></li>
            <li><a href="{{ route('front.terms.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.terms.heading') }}</a></li>
            <li><a href="{{ route('front.privacy.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.privacy.heading') }}</a></li>
            <li><a href="{{ route('front.cookie-policy.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.cookie-policy.heading') }}</a></li>
            <li><a href="{{ route('front.legal.index') }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.pages.legal.heading') }}</a></li>
        </ul>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.sitemap.cinema_heading') }}</x-front.section-heading>

        <div class="mt-4 space-y-6">
            @foreach ($cinemas as $cinema)
                <div>
                    <p class="font-bold">
                        <a href="{{ route('front.cinema.show', ['slug' => $cinema->slug]) }}" class="underline decoration-stone-400 hover:text-brand">
                            {{ $cinema->name }}
                        </a>
                    </p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        <li><a href="{{ route('front.schedule.index', ['slug' => $cinema->slug]) }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.schedule.heading') }}</a></li>
                        <li><a href="{{ route('front.news.index', ['slug' => $cinema->slug]) }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.news.heading') }}</a></li>
                        <li><a href="{{ route('front.establishment.index', ['slug' => $cinema->slug]) }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.establishment.heading') }}</a></li>
                        <li><a href="{{ route('front.access.index', ['slug' => $cinema->slug]) }}" class="underline decoration-stone-400 hover:text-brand">{{ __('front.access.heading') }}</a></li>
                    </ul>
                </div>
            @endforeach
        </div>
    </section>
</x-front.page>
