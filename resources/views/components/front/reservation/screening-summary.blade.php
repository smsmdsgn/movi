{{--
    予約フローの上映情報（7.6.1-1 / 4.3.7-1〜4）。P-31 以降のページが共通で使う。

    状態を持たない表示のため Blade コンポーネントとする（front-ui）。上映回は
    ページ側のコントローラが `booking.movie` / `booking.format` / `theater` を
    読み込んだうえで渡すこと（preventLazyLoading）。

    $screening: Screening
    $cinema: Cinema（上映回から定まる館。`CurrentCinemaService::remember()` が確定させたもの）
--}}
@props(['screening', 'cinema'])
@php
    /** @var \App\Models\Screening $screening */
    /** @var \App\Models\Cinema $cinema */
    $weekdays = __('front.schedule.weekdays');
    $startsAt = $screening->starts_at;
@endphp
<dl {{ $attributes->merge(['class' => 'grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 border border-stone-300 p-3 text-sm']) }}>
    <dt class="text-stone-600">{{ __('front.reservation.screening.movie') }}</dt>
    <dd class="font-bold">{{ $screening->booking->movie->title }}</dd>

    <dt class="text-stone-600">{{ __('front.reservation.screening.cinema') }}</dt>
    <dd>{{ $cinema->name }}</dd>

    <dt class="text-stone-600">{{ __('front.reservation.screening.theater') }}</dt>
    <dd>{{ $screening->theater->name }}</dd>

    <dt class="text-stone-600">{{ __('front.reservation.screening.starts_at') }}</dt>
    <dd class="tabular-nums">{{ __('front.reservation.screening.datetime', [
        'date' => $startsAt->format('Y/n/j'),
        'weekday' => $weekdays[$startsAt->dayOfWeek],
        'time' => $startsAt->format('H:i'),
    ]) }}</dd>

    <dt class="text-stone-600">{{ __('front.reservation.screening.format') }}</dt>
    <dd>{{ $screening->booking->format->name }}</dd>
</dl>
