{{--
    フード・ドリンクメニュー（P-09、7.1.1 / 4.9.1。工程7-f）。

    メニュー本文はダミー（`lang/ja/front.php` の固定文言）。取り扱い品目は館により
    異なるため、各館の施設案内（P-27）への導線を添える。

    $cinemas: Collection<Cinema>（id 順）
--}}
<x-front.page
    :title="__('front.pages.food.title')"
    :heading="__('front.pages.food.heading')"
    :description="__('front.pages.food.description')"
>
    <section>
        <x-front.section-heading>{{ __('front.pages.food.menu_heading') }}</x-front.section-heading>

        <dl class="mt-4 divide-y divide-stone-200 border border-stone-300">
            @foreach (__('front.pages.food.items') as $item)
                <div class="flex items-center justify-between p-3">
                    <dt>{{ $item['name'] }}</dt>
                    <dd class="tabular-nums">{{ $item['price'] }}</dd>
                </div>
            @endforeach
        </dl>

        <p class="mt-3">{{ __('front.pages.food.variation_note') }}</p>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.food.establishment_heading') }}</x-front.section-heading>

        <ul class="mt-4 space-y-2">
            @foreach ($cinemas as $cinema)
                <li>
                    <a href="{{ route('front.establishment.index', ['slug' => $cinema->slug]) }}" class="underline decoration-stone-400 hover:text-brand">
                        {{ $cinema->name }}
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
</x-front.page>
