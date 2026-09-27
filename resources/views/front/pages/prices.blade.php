{{--
    料金表・割引サービス（P-08、7.1.1 / 4.9.1 / 6.5。工程7-f）。

    券種・上映規格・座席の追加料金はマスタから動的に生成する（4.9.1「料金表ページは
    券種マスタから動的に生成する」）。割引の金額は `PricingService` の定数を参照し、
    直書きしない（6.5.2 / pricing スキル）。

    $ticketTypes: Collection<TicketType>（display_order → id 順）
    $formats: Collection<Format>（id 順）
    $surchargedSeatTypes: Collection<SeatType>（surcharge > 0 のみ、id 順）
--}}
@php
    $lateShowAmount = __('front.reservation.yen', ['amount' => number_format(\App\Services\PricingService::LATE_SHOW_DISCOUNT)]);
    $pairAmount = __('front.reservation.yen', ['amount' => number_format(\App\Services\PricingService::PAIR_UNIT_PRICE)]);
@endphp
<x-front.page
    :title="__('front.pages.prices.title')"
    :heading="__('front.pages.prices.heading')"
    :description="__('front.pages.prices.description')"
>
    <section>
        <x-front.section-heading>{{ __('front.pages.prices.ticket_types_heading') }}</x-front.section-heading>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full border-collapse border border-stone-300 text-left">
                <thead>
                    <tr class="bg-stone-100">
                        <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.prices.column_ticket_type') }}</th>
                        <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.prices.column_price') }}</th>
                        <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.prices.column_condition') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ticketTypes as $ticketType)
                        <tr>
                            <td class="border border-stone-300 p-2">{{ $ticketType->name }}</td>
                            <td class="border border-stone-300 p-2 tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($ticketType->price)]) }}</td>
                            <td class="border border-stone-300 p-2">{{ $ticketType->condition }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3">{{ __('front.pages.prices.no_member_price_diff') }}</p>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.prices.format_surcharge_heading') }}</x-front.section-heading>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full border-collapse border border-stone-300 text-left">
                <thead>
                    <tr class="bg-stone-100">
                        <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.prices.column_format') }}</th>
                        <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.prices.column_surcharge') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($formats as $format)
                        <tr>
                            <td class="border border-stone-300 p-2">{{ $format->name }}</td>
                            <td class="border border-stone-300 p-2 tabular-nums">
                                {{ $format->default_surcharge > 0 ? __('front.reservation.yen', ['amount' => number_format($format->default_surcharge)]) : __('front.pages.prices.no_surcharge') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3">{{ __('front.pages.prices.format_surcharge_note') }}</p>
    </section>

    @if ($surchargedSeatTypes->isNotEmpty())
        <section class="mt-8">
            <x-front.section-heading>{{ __('front.pages.prices.seat_surcharge_heading') }}</x-front.section-heading>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full border-collapse border border-stone-300 text-left">
                    <thead>
                        <tr class="bg-stone-100">
                            <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.prices.column_seat_type') }}</th>
                            <th scope="col" class="border border-stone-300 p-2">{{ __('front.pages.prices.column_surcharge') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($surchargedSeatTypes as $seatType)
                            <tr>
                                <td class="border border-stone-300 p-2">{{ $seatType->name }}</td>
                                <td class="border border-stone-300 p-2 tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($seatType->surcharge)]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.prices.discount_heading') }}</x-front.section-heading>

        <ul class="mt-4 space-y-2">
            <li>{{ __('front.pages.prices.discount_late_show', ['hour' => \App\Services\PricingService::LATE_SHOW_FROM_HOUR, 'amount' => $lateShowAmount]) }}</li>
            <li>{{ __('front.pages.prices.discount_pair', ['size' => \App\Services\PricingService::PAIR_SIZE, 'amount' => $pairAmount]) }}</li>
        </ul>

        <p class="mt-3">{{ __('front.pages.prices.discount_note') }}</p>
    </section>

    <section class="mt-8">
        <x-front.section-heading>{{ __('front.pages.prices.box_office_heading') }}</x-front.section-heading>

        <p class="mt-4">{{ __('front.pages.prices.box_office_note') }}</p>
    </section>
</x-front.page>
