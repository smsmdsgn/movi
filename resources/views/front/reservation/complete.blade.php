{{--
    予約完了（P-38、7.13）。

    操作を伴わないため Livewire を用いず、コントローラが組み立てたページのみを返す
    （13.4.3 / front-ui「状態を持たない表示は Blade」）。

    $reservation: Reservation（user / seats.seat / seats.ticketType / screening を読み込み済み）
    $screening: Screening（$reservation->screening。共通コンポーネントへ渡す）
    $seats: Collection<ReservationSeat>（座席表と同じ並び順）
    $cinema: Cinema（予約から定まる館。`CurrentCinemaService::remember()` が確定させたもの）

    **入場用QRコード（7.13-2）・領収書（7.13-4）・予約確定メールの案内（7.13-5）は
    本画面では未実装**（12章 残課題36）。QRは工程8（`endroid/qr-code` の導入）、メールは
    メール実装の工程（8.3 / 21.1）で加える。
--}}
@php
    /** @var \App\Models\Reservation $reservation */
    /** @var \App\Models\Screening $screening */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ReservationSeat> $seats */
    /** @var \App\Models\Cinema $cinema */
@endphp
<x-front.layout
    :title="__('front.reservation.complete.title', ['movie' => $screening->booking->movie->title, 'cinema' => $cinema->name])"
    :description="__('front.reservation.complete.description')"
    robots="noindex, nofollow"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => route('front.cinema.show', ['slug' => $cinema->slug])],
            {{-- 座席選択への導線は置かない。確定済みの予約に対して選び直す操作は無い。 --}}
            ['label' => __('front.reservation.complete.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.reservation.complete.heading') }}</h1>
        <p class="mt-2 text-sm">{{ __('front.reservation.complete.lead') }}</p>

        {{-- 7.13-1 予約番号（4桁区切り）。この画面で最も重要な情報のため最初に置く。 --}}
        <section aria-labelledby="reservation-no-heading" class="mt-6 border-2 border-red-800 p-4">
            <h2 id="reservation-no-heading" class="text-sm font-bold">{{ __('front.reservation.complete.reservation_no_heading') }}</h2>
            <p class="mt-1 text-3xl font-bold tabular-nums tracking-wider">{{ $reservation->formattedReservationNo() }}</p>
            <p class="mt-2 text-xs text-stone-600">{{ __('front.reservation.complete.reservation_no_note') }}</p>
        </section>

        {{-- 7.13-3 予約内容 --}}
        <x-front.reservation.screening-summary :screening="$screening" :cinema="$cinema" class="mt-6" />

        <section aria-labelledby="seats-heading" class="mt-4 border border-stone-300 p-4">
            <h2 id="seats-heading" class="font-bold">
                {{ __('front.reservation.complete.seats_heading') }}
                <span class="ml-1 text-sm font-normal text-stone-600">{{ __('front.reservation.complete.count', ['count' => $seats->count()]) }}</span>
            </h2>

            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($seats as $row)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-stone-200 pb-2 last:border-b-0 last:pb-0">
                        <span class="font-bold tabular-nums">{{ $row->seat->displayName() }}</span>
                        <span>{{ $row->ticketType->name }}</span>
                        <span class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($row->amount)]) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- **小計と割引額は表示しない**（4.3.16 / 12章 残課題36）。保存しているのは
             席ごとの確定額（`t_reservation_seats.amount`＝割引適用後）と支払金額だけで
             あり、割引前の金額（6.5.4 の「小計」）は持たない。確定後にマスタから
             引き直すと、料金改定で過去の予約の金額が動く（6.5.5 の【根拠】）。 --}}
        <section aria-labelledby="amount-heading" class="mt-4 border border-stone-300 p-4">
            <h2 id="amount-heading" class="font-bold">{{ __('front.reservation.complete.amount_heading') }}</h2>

            <dl class="mt-3">
                <div class="flex items-baseline justify-between text-base font-bold">
                    <dt>{{ __('front.reservation.tickets.total') }}</dt>
                    <dd class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($reservation->total_amount)]) }}</dd>
                </div>
            </dl>

            <p class="mt-2 text-xs text-stone-600">{{ __('front.reservation.complete.amount_note') }}</p>
        </section>

        {{-- 7.13-2 / 7.13-4 / 7.13-5 は未実装（12章 残課題36）。窓口での代替手段を案内する。 --}}
        <section aria-labelledby="entry-heading" class="mt-4 border border-stone-300 bg-stone-50 p-4">
            <h2 id="entry-heading" class="font-bold">{{ __('front.reservation.complete.entry_heading') }}</h2>
            <p class="mt-2 text-sm">{{ __('front.reservation.complete.entry_pending') }}</p>
        </section>

        {{-- 7.13-6 マイページまたは予約照会画面へのリンク --}}
        <section aria-labelledby="links-heading" class="mt-6">
            <h2 id="links-heading" class="font-bold">{{ __('front.reservation.complete.links_heading') }}</h2>

            <ul class="mt-3 space-y-2 text-sm">
                @auth
                    <li>
                        <a href="{{ route('front.mypage.index') }}" class="inline-block border border-stone-400 px-4 py-3 underline decoration-stone-400 hover:bg-stone-100">
                            {{ __('front.reservation.complete.to_mypage') }}
                        </a>
                    </li>
                @endauth
                <li>
                    <a href="{{ route('front.lookup.index') }}" class="inline-block border border-stone-400 px-4 py-3 underline decoration-stone-400 hover:bg-stone-100">
                        {{ __('front.reservation.complete.to_lookup') }}
                    </a>
                </li>
                <li>
                    <a href="{{ route('front.cinema.show', ['slug' => $cinema->slug]) }}" class="inline-block px-1 py-2 underline decoration-stone-400">
                        {{ __('front.reservation.complete.to_cinema') }}
                    </a>
                </li>
            </ul>
        </section>
    </div>
</x-front.layout>
