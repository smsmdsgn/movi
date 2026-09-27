{{--
    採用情報（P-12、7.1.1 / 4.9.1 / 4.9.2。工程7-f）。ダミー本文のため冒頭に注記を出す。

    採用に関する問い合わせは、お問い合わせフォーム（P-14）の選択肢に含めず、
    本ページの案内先へ誘導する（4.9.2）。
--}}
<x-front.page
    :title="__('front.pages.recruit.title')"
    :heading="__('front.pages.recruit.heading')"
    :description="__('front.pages.recruit.description')"
>
    <p class="border border-stone-300 bg-stone-50 p-3">{{ __('front.pages.demo_notice') }}</p>

    <section>
        <p>{{ __('front.pages.recruit.lead') }}</p>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.recruit.positions_heading') }}</x-front.section-heading>

        <ul class="mt-4 list-inside list-disc space-y-1">
            @foreach (__('front.pages.recruit.positions') as $position)
                <li>{{ $position }}</li>
            @endforeach
        </ul>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.recruit.contact_heading') }}</x-front.section-heading>

        <p class="mt-4">{{ __('front.pages.recruit.contact_note') }}</p>
        <p class="mt-2 font-bold">{{ __('front.pages.recruit.contact_detail') }}</p>
    </section>
</x-front.page>
