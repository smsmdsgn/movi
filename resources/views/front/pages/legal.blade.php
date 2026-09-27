{{--
    特定商取引法に基づく表記（P-19、7.1.1 / 4.4 / 4.9.1 / 7.11.1。工程7-f）。
    表形式で表示する。ダミー本文のため冒頭に注記を出す。

    キャンセル・返金の条件は 4.4（手数料なし・全額返金・上映開始20分前まで）と一致させる。
    支払方法はクレジットカードのみ（7.11.1。オンライン予約はクレジットカードのみで
    HogePay 等の架空の決済サービスはデモでは使えない）。
--}}
<x-front.page
    :title="__('front.pages.legal.title')"
    :heading="__('front.pages.legal.heading')"
    :description="__('front.pages.legal.description')"
>
    <p class="border border-stone-300 bg-stone-50 p-3">{{ __('front.pages.demo_notice') }}</p>

    <section>
        <dl class="divide-y divide-stone-200 border border-stone-300">
            @foreach (__('front.pages.legal.rows', ['minutes' => \App\Models\Screening::CANCEL_DEADLINE_MINUTES]) as $row)
                <div class="grid grid-cols-1 gap-1 p-3 md:grid-cols-3">
                    <dt class="font-bold text-stone-600 md:col-span-1">{{ $row['label'] }}</dt>
                    <dd class="md:col-span-2">{{ $row['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</x-front.page>
