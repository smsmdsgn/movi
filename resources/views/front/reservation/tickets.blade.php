{{--
    券種選択（P-35、7.10）。選択と金額の表示は Livewire コンポーネントが担い、
    本ビューは共通レイアウト・メタ情報・上映情報を組み立てる（P-33・P-34 と同じ分担）。

    $screening: Screening（booking.movie / booking.format / theater を読み込み済み）
    $cinema: Cinema（上映回から定まる館。`CurrentCinemaService::remember()` が確定させたもの）
--}}
@php
    /** @var \App\Models\Screening $screening */
    /** @var \App\Models\Cinema $cinema */
@endphp
<x-front.layout
    :title="__('front.reservation.tickets.title', ['movie' => $screening->booking->movie->title, 'cinema' => $cinema->name])"
    :description="__('front.reservation.tickets.description')"
    robots="noindex, nofollow"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        {{-- 予約フローは館配下のURLではないが、戻り先を示すため館までの経路を出す（19.3-9）。
             インデックス対象外のため BreadcrumbList の構造化データは付けない。 --}}
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => route('front.cinema.show', ['slug' => $cinema->slug])],
            ['label' => __('front.reservation.heading'), 'url' => route('front.reservation.seats', ['id' => $screening->id])],
            ['label' => __('front.reservation.tickets.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.reservation.tickets.heading') }}</h1>

        <x-front.reservation.screening-summary :screening="$screening" :cinema="$cinema" class="mt-4" />

        <div class="mt-6">
            <livewire:front.reservation.ticket-selection :screening="$screening" />
        </div>
    </div>
</x-front.layout>
