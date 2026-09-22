{{--
    予約の明細（7.19「明細の構成要素」1〜7）。**予約照会（P-07）とマイページの予約詳細
    （P-06、7.14）が共有する。**

    状態を持たない表示のため Blade コンポーネントとする（13.4.3 / front-ui）。
    キャンセルの導線（構成要素8）は操作を伴うため `x-front.reservation.cancel-section`
    に分ける。

    呼び出し側が `seats.seat` / `seats.ticketType` / `screening.theater` /
    `screening.booking.movie` / `screening.booking.format` / `screening.booking.cinema`
    を読み込んだうえで渡すこと（`preventLazyLoading`）。

    $reservation: Reservation（`paid` または `cancelled`）
    $idPrefix: 見出しのIDの接頭辞（同一ページに複数置かれても重複しないようにする）

    座席の並び順（7.19-4）は `Reservation::seatsInGridOrder()` が持つ。**呼び出し側で
    並べ替えない**（明細の描き方を1箇所に閉じる。4.3.8「条件の集約」）。

    **文言は `front.lookup.*` を共有する**（4.5.3「共有している文言」）。同じ意味の語を
    2系統に持たない。
--}}
@props(['reservation', 'idPrefix'])
@php
    /** @var \App\Models\Reservation $reservation */
    $screening = $reservation->screening;
    $seats = $reservation->seatsInGridOrder();
    $isCancelled = $reservation->status === \App\Enums\ReservationStatus::Cancelled;
@endphp
<div {{ $attributes }}>
    <h2 class="text-xl font-bold">{{ __('front.lookup.detail_heading') }}</h2>

    {{-- ご予約の状態（7.19-1）。状態を色のみで区別せず、文言でも示す（5.2 / 18.2）。 --}}
    <p class="mt-3 inline-block border-2 px-3 py-1 text-sm font-bold {{ $isCancelled ? 'border-stone-400 bg-stone-100 text-stone-700' : 'border-red-800 text-red-900' }}">
        {{ __('front.lookup.status_heading') }}:
        {{ __('front.lookup.status.'.$reservation->status->value) }}
    </p>

    @if ($isCancelled)
        {{-- 12章 残課題37。上映回はキャンセル後に変更されうるため、現在の値である旨を断る。 --}}
        <p class="mt-2 border border-stone-300 bg-stone-50 p-3 text-sm">
            {{ __('front.lookup.cancelled_note') }}
            @if ($reservation->cancelled_at !== null)
                <span class="mt-1 block tabular-nums text-stone-700">
                    {{ __('front.lookup.cancelled_at') }}: {{ $reservation->cancelled_at->format('Y/n/j H:i') }}
                </span>
            @endif
        </p>
    @elseif ($reservation->isCheckedIn())
        <p class="mt-2 border border-stone-300 bg-stone-50 p-3 text-sm">{{ __('front.lookup.checked_in_note') }}</p>
    @endif

    {{-- 予約番号（7.19-2）。上映情報より前に置く（7.13-1 と同じ扱い）。 --}}
    <section aria-labelledby="{{ $idPrefix }}-no-heading" class="mt-6 border-2 border-stone-400 p-4">
        <h3 id="{{ $idPrefix }}-no-heading" class="text-sm font-bold">{{ __('front.lookup.reservation_no_heading') }}</h3>
        <p class="mt-1 text-3xl font-bold tabular-nums tracking-wider">{{ $reservation->formattedReservationNo() }}</p>
    </section>

    {{-- 上映情報（7.19-3）。P-31 以降と同じ共通部品。 --}}
    <x-front.reservation.screening-summary :screening="$screening" :cinema="$screening->booking->cinema" class="mt-4" />

    {{-- お座席（7.19-4）。 --}}
    <section aria-labelledby="{{ $idPrefix }}-seats-heading" class="mt-4 border border-stone-300 p-4">
        <h3 id="{{ $idPrefix }}-seats-heading" class="font-bold">
            {{ __('front.lookup.seats_heading') }}
            <span class="ml-1 text-sm font-normal text-stone-600">{{ __('front.lookup.count', ['count' => $seats->count()]) }}</span>
        </h3>

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

    {{-- お支払金額（7.19-5）。**小計と割引額は表示しない**（12章 残課題36-d。P-38 と同じ
         制約）。保存しているのは席ごとの確定額（割引適用後）と支払金額だけであり、
         割引前の金額を持たない。 --}}
    <section aria-labelledby="{{ $idPrefix }}-amount-heading" class="mt-4 border border-stone-300 p-4">
        <h3 id="{{ $idPrefix }}-amount-heading" class="font-bold">{{ __('front.lookup.amount_heading') }}</h3>

        <dl class="mt-3">
            <div class="flex items-baseline justify-between text-base font-bold">
                <dt>{{ __('front.reservation.tickets.total') }}</dt>
                <dd class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($reservation->total_amount)]) }}</dd>
            </div>
        </dl>

        <p class="mt-2 text-xs text-stone-600">{{ __('front.lookup.amount_note') }}</p>
    </section>

    {{-- 入場用QRコード（7.19-6）は工程8で加える（12章 残課題18 / 36-a）。
         キャンセル済みの予約には入場の案内を出さない。 --}}
    @if (! $isCancelled)
        <section aria-labelledby="{{ $idPrefix }}-entry-heading" class="mt-4 border border-stone-300 bg-stone-50 p-4">
            <h3 id="{{ $idPrefix }}-entry-heading" class="font-bold">{{ __('front.lookup.entry_heading') }}</h3>
            <p class="mt-2 text-sm">{{ __('front.lookup.entry_pending') }}</p>
        </section>
    @endif

    {{-- 領収書（7.19-7）は画面設計が未確定のため案内のみ（12章 残課題36-b）。 --}}
    <section aria-labelledby="{{ $idPrefix }}-receipt-heading" class="mt-4 border border-stone-300 bg-stone-50 p-4">
        <h3 id="{{ $idPrefix }}-receipt-heading" class="font-bold">{{ __('front.lookup.receipt_heading') }}</h3>
        <p class="mt-2 text-sm">{{ __('front.lookup.receipt_pending') }}</p>
    </section>
</div>
