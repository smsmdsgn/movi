{{--
    プライバシーポリシー（P-17、7.1.1 / 4.9.1。工程7-f）。ダミー本文のため冒頭に注記を出す。
    見出し構成は実在サイトに準じる（4.9.1「法務関連ページの内容」）。
--}}
<x-front.page
    :title="__('front.pages.privacy.title')"
    :heading="__('front.pages.privacy.heading')"
    :description="__('front.pages.privacy.description')"
>
    <p class="border border-stone-300 bg-stone-50 p-3">{{ __('front.pages.demo_notice') }}</p>

    @foreach (__('front.pages.privacy.sections') as $section)
        <section class="mt-8 first:mt-0">
            <x-front.section-heading>{{ $section['heading'] }}</x-front.section-heading>
            <p class="mt-4">{{ $section['body'] }}</p>
        </section>
    @endforeach
</x-front.page>
