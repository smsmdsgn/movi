{{--
    利用規約（P-16、7.1.1 / 4.3.7 / 4.4 / 4.9.1。工程7-f）。ダミー本文のため冒頭に注記を出す。

    第4条（オンラインチケット購入）に `id="online-ticket"` を付け、同意画面（P-32、
    `resources/views/front/reservation/agreement-form.blade.php`）からの
    「利用規約の全文を読む」リンク（`route('front.terms.index').'#online-ticket'`）の
    遷移先とする。

    第4条の本文は `front.reservation.agreement.terms.*`（no_change・cancel_deadline・
    late_entry）をそのまま参照し、同意画面と表記を一致させる。別の文言を書かない。
    これに 4.4 の「手数料なし・全額返金」「座席の一部のみのキャンセル不可」
    「入場済みの予約はキャンセル不可」を加える。
--}}
<x-front.page
    :title="__('front.pages.terms.title')"
    :heading="__('front.pages.terms.heading')"
    :description="__('front.pages.terms.description')"
>
    <p class="border border-stone-300 bg-stone-50 p-3">{{ __('front.pages.demo_notice') }}</p>

    <section>
        <x-front.section-heading>{{ __('front.pages.terms.article1_heading') }}</x-front.section-heading>
        <p class="mt-4">{{ __('front.pages.terms.article1_body') }}</p>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.terms.article2_heading') }}</x-front.section-heading>
        <p class="mt-4">{{ __('front.pages.terms.article2_body') }}</p>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.terms.article3_heading') }}</x-front.section-heading>
        <ul class="mt-4 list-inside list-disc space-y-1">
            @foreach (__('front.pages.terms.article3_items') as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </section>

    <section id="online-ticket" class="mt-8">
        <x-front.section-heading>{{ __('front.pages.terms.article4_heading') }}</x-front.section-heading>
        <p class="mt-4">{{ __('front.pages.terms.article4_lead') }}</p>
        <ul class="mt-3 list-inside list-disc space-y-1">
            <li>{{ __('front.reservation.agreement.terms.no_change') }}</li>
            <li>{{ __('front.reservation.agreement.terms.cancel_deadline') }}</li>
            <li>{{ __('front.reservation.agreement.terms.late_entry') }}</li>
            <li>{{ __('front.pages.terms.no_fee_full_refund') }}</li>
            <li>{{ __('front.pages.terms.no_partial_cancel') }}</li>
            <li>{{ __('front.pages.terms.no_cancel_after_checkin') }}</li>
        </ul>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.terms.article5_heading') }}</x-front.section-heading>
        <p class="mt-4">{{ __('front.pages.terms.article5_body') }}</p>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.terms.article6_heading') }}</x-front.section-heading>
        <p class="mt-4">{{ __('front.pages.terms.article6_body') }}</p>
    </section>
</x-front.page>
