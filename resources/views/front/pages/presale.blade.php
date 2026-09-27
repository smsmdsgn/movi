{{--
    前売り券情報（P-10、7.1.1 / 4.9.1。工程7-f）。ダミー本文のため冒頭に注記を出す。

    予約フローに前売り券の入力手段が無いため、オンライン予約では利用できず
    劇場窓口での引き換えとなる旨を案内する。
--}}
<x-front.page
    :title="__('front.pages.presale.title')"
    :heading="__('front.pages.presale.heading')"
    :description="__('front.pages.presale.description')"
>
    <p class="border border-stone-300 bg-stone-50 p-3">{{ __('front.pages.demo_notice') }}</p>

    <section>
        <p>{{ __('front.pages.presale.lead') }}</p>
        <p class="mt-3">{{ __('front.pages.presale.note') }}</p>
    </section>
</x-front.page>
