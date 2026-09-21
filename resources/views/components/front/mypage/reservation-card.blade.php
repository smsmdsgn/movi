{{--
    マイページの予約一覧の1件（P-05、7.14 構成要素2・3）。

    状態を持たない表示のため Blade コンポーネントとする（front-ui）。
    呼び出し側が `screening.booking.movie` / `screening.booking.cinema` を読み込み、
    `withCount('seats')` を掛けたうえで渡すこと（`preventLazyLoading`）。

    $reservation: Reservation
--}}
@props(['reservation'])
@php
    /** @var \App\Models\Reservation $reservation */
    $screening = $reservation->screening;
    $startsAt = $screening->starts_at;
    $weekdays = __('front.schedule.weekdays');
    $isCancelled = $reservation->status === \App\Enums\ReservationStatus::Cancelled;
@endphp
<li class="border border-stone-300 p-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="font-bold">{{ $screening->booking->movie->title }}</p>

        {{-- 状態を色のみで区別せず、文言でも示す（5.2 / 18.2）。 --}}
        <span class="border px-2 py-0.5 text-xs font-bold {{ $isCancelled ? 'border-stone-400 bg-stone-100 text-stone-700' : 'border-red-800 text-red-900' }}">
            {{ __('front.lookup.status.'.$reservation->status->value) }}
        </span>
    </div>

    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt class="text-stone-600">{{ __('front.reservation.screening.cinema') }}</dt>
        <dd>{{ $screening->booking->cinema->name }}</dd>

        <dt class="text-stone-600">{{ __('front.reservation.screening.starts_at') }}</dt>
        <dd class="tabular-nums">{{ __('front.reservation.screening.datetime', [
            'date' => $startsAt->format('Y/n/j'),
            'weekday' => $weekdays[$startsAt->dayOfWeek],
            'time' => $startsAt->format('H:i'),
        ]) }}</dd>

        <dt class="text-stone-600">{{ __('front.mypage.reservation.no') }}</dt>
        <dd class="tabular-nums">{{ $reservation->formattedReservationNo() }}</dd>

        <dt class="text-stone-600">{{ __('front.mypage.reservation.seats') }}</dt>
        <dd class="tabular-nums">{{ __('front.mypage.reservation.seats_count', ['count' => $reservation->seats_count]) }}</dd>

        <dt class="text-stone-600">{{ __('front.mypage.reservation.amount') }}</dt>
        <dd class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($reservation->total_amount)]) }}</dd>
    </dl>

    @if ($isCancelled)
        {{-- 12章 残課題37・40。キャンセル済みだけが残る上映回は A-09 がシアター・開始時刻を
             変更できるため、ここに出る劇場名・上映日時は現在値であり予約時点とは限らない
             （P-07 の `front.lookup.cancelled_note` と同じ手当て。4.3.17）。 --}}
        <p class="mt-2 border border-stone-300 bg-stone-50 p-2 text-xs">{{ __('front.lookup.cancelled_note') }}</p>
    @endif

    <p class="mt-3">
        <a
            href="{{ route('front.mypage.reservation.show', ['id' => $reservation->id]) }}"
            class="inline-block border border-stone-400 px-4 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100"
        >
            {{ __('front.mypage.reservation.detail') }}
        </a>
    </p>
</li>
