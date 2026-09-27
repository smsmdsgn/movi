{{--
    よくある質問（P-11、7.1.1 / 4.3.4 / 4.4 / 4.5.1 / 4.5.2 / 4.9.1。工程7-f）。

    区分ごとに `<dl>` を用いる（JSを使わない）。

    入場の回答は `front.reservation.agreement.terms.late_entry` と同じ文言を参照する
    （同意画面 P-32 と表記を一致させるため）。入場方法は「劇場の窓口で予約番号を
    お伝えください」とし、QRコードによる入場（未実装）を約束しない。

    無料鑑賞券のオンライン利用は `front.mypage.free_ticket.pending` と同じ文言を参照し、
    「現在準備中」であることを示す（12章 残課題31。オンラインでの選択は未実装）。
--}}
@php
    /* 仕様値（4.3.4・4.4・4.5.1）は定数から差し込む。配列の文言にも置換は効く（Translator::getLine()）。 */
    $replacements = [
        'max' => \App\Services\SeatLockService::MAX_SEATS_PER_HOLDER,
        'minutes' => \App\Models\Screening::CANCEL_DEADLINE_MINUTES,
        'stamps' => \App\Models\FreeTicket::STAMPS_PER_TICKET,
    ];
@endphp
<x-front.page
    :title="__('front.pages.faq.title')"
    :heading="__('front.pages.faq.heading')"
    :description="__('front.pages.faq.description')"
>
    <section>
        <x-front.section-heading>{{ __('front.pages.faq.ticket.heading') }}</x-front.section-heading>

        <dl class="mt-4 space-y-4">
            @foreach (__('front.pages.faq.ticket.items', $replacements) as $item)
                <div class="border border-stone-300 p-3">
                    <dt class="font-bold">{{ $item['q'] }}</dt>
                    <dd class="mt-1">{{ $item['a'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.faq.payment.heading') }}</x-front.section-heading>

        <dl class="mt-4 space-y-4">
            @foreach (__('front.pages.faq.payment.items') as $item)
                <div class="border border-stone-300 p-3">
                    <dt class="font-bold">{{ $item['q'] }}</dt>
                    <dd class="mt-1">{{ $item['a'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.faq.entry.heading') }}</x-front.section-heading>

        <dl class="mt-4 space-y-4">
            <div class="border border-stone-300 p-3">
                <dt class="font-bold">{{ __('front.pages.faq.entry.question') }}</dt>
                <dd class="mt-1">
                    {{ __('front.reservation.agreement.terms.late_entry') }}
                    {{ __('front.pages.faq.entry.answer_note') }}
                </dd>
            </div>
        </dl>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.faq.membership.heading') }}</x-front.section-heading>

        <dl class="mt-4 space-y-4">
            @foreach (__('front.pages.faq.membership.items', $replacements) as $item)
                <div class="border border-stone-300 p-3">
                    <dt class="font-bold">{{ $item['q'] }}</dt>
                    {{-- `a_key` は他画面と同じ文言キーを参照する項目（表記を食い違わせない）。 --}}
                    <dd class="mt-1">{{ isset($item['a_key']) ? __($item['a_key']) : $item['a'] }}</dd>
                </div>
            @endforeach

            <div class="border border-stone-300 p-3">
                <dt class="font-bold">{{ __('front.pages.faq.membership.question_free_ticket_online') }}</dt>
                <dd class="mt-1">{{ __('front.mypage.free_ticket.pending') }}</dd>
            </div>
        </dl>
    </section>
</x-front.page>
