{{--
    座席選択（P-31、7.6）。座席表は Livewire コンポーネントが描画し、本ビューは
    共通レイアウト・メタ情報・上映情報（7.6.1-1）を組み立てる。

    $screening: Screening（booking.movie / booking.format / theater を読み込み済み）
    $cinema: Cinema（上映回から定まる館。`CurrentCinemaService::remember()` が確定させたもの）
--}}
@php
    /** @var \App\Models\Screening $screening */
    /** @var \App\Models\Cinema $cinema */
    $weekdays = __('front.schedule.weekdays');
    $startsAt = $screening->starts_at;
    $datetime = __('front.reservation.screening.datetime', [
        'date' => $startsAt->format('Y/n/j'),
        'weekday' => $weekdays[$startsAt->dayOfWeek],
        'time' => $startsAt->format('H:i'),
    ]);
    $cinemaTopUrl = route('front.cinema.show', ['slug' => $cinema->slug]);
@endphp
<x-front.layout
    :title="__('front.reservation.title', ['movie' => $screening->booking->movie->title, 'cinema' => $cinema->name])"
    :description="\Illuminate\Support\Str::limit(__('front.reservation.description', [
        'cinema' => $cinema->name,
        'theater' => $screening->theater->name,
        'datetime' => $datetime,
        'movie' => $screening->booking->movie->title,
    ]), 120, '')"
    robots="noindex, nofollow"
>
    <div class="mx-auto max-w-5xl px-4 py-6">
        {{-- 予約フローは館配下のURLではないが、戻り先を示すため館までの経路を出す（19.3-9）。
             インデックス対象外のため BreadcrumbList の構造化データは付けない。 --}}
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => $cinemaTopUrl],
            ['label' => __('front.schedule.heading'), 'url' => route('front.schedule.index', ['slug' => $cinema->slug])],
            ['label' => __('front.reservation.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.reservation.heading') }}</h1>

        <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 border border-stone-300 p-3 text-sm">
            <dt class="text-stone-600">{{ __('front.reservation.screening.movie') }}</dt>
            <dd class="font-bold">{{ $screening->booking->movie->title }}</dd>

            <dt class="text-stone-600">{{ __('front.reservation.screening.cinema') }}</dt>
            <dd>{{ $cinema->name }}</dd>

            <dt class="text-stone-600">{{ __('front.reservation.screening.theater') }}</dt>
            <dd>{{ $screening->theater->name }}</dd>

            <dt class="text-stone-600">{{ __('front.reservation.screening.starts_at') }}</dt>
            <dd class="tabular-nums">{{ $datetime }}</dd>

            <dt class="text-stone-600">{{ __('front.reservation.screening.format') }}</dt>
            <dd>{{ $screening->booking->format->name }}</dd>
        </dl>

        <div class="mt-6">
            <livewire:front.reservation.seat-selection :screening="$screening" />
        </div>
    </div>
</x-front.layout>
