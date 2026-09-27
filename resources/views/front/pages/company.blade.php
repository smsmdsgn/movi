{{--
    会社情報（P-13、7.1.1 / 4.9.1。工程7-f）。ダミー本文のため冒頭に注記を出す。
    会社名はフッターの著作権表記（MOVI CO., LTD.）に合わせる。
--}}
<x-front.page
    :title="__('front.pages.company.title')"
    :heading="__('front.pages.company.heading')"
    :description="__('front.pages.company.description')"
>
    <p class="border border-stone-300 bg-stone-50 p-3">{{ __('front.pages.demo_notice') }}</p>

    <section>
        <dl class="divide-y divide-stone-200 border border-stone-300">
            @foreach (__('front.pages.company.rows') as $row)
                <div class="grid grid-cols-1 gap-1 p-3 md:grid-cols-3">
                    <dt class="font-bold text-stone-600 md:col-span-1">{{ $row['label'] }}</dt>
                    <dd class="md:col-span-2">{{ $row['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</x-front.page>
