{{--
    会員／非会員の選択（P-33、7.8）。選択の操作は Livewire コンポーネントが担い、
    本ビューは共通レイアウト・メタ情報・上映情報を組み立てる。

    $screening: Screening（booking.movie / booking.format / theater を読み込み済み）
    $cinema: Cinema（上映回から定まる館。`CurrentCinemaService::remember()` が確定させたもの）
--}}
@php
    /** @var \App\Models\Screening $screening */
    /** @var \App\Models\Cinema $cinema */
@endphp
<x-front.layout
    :title="__('front.reservation.identify.title', ['movie' => $screening->booking->movie->title, 'cinema' => $cinema->name])"
    :description="__('front.reservation.identify.description')"
    robots="noindex, nofollow"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        {{-- 予約フローは館配下のURLではないが、戻り先を示すため館までの経路を出す（19.3-9）。
             インデックス対象外のため BreadcrumbList の構造化データは付けない。 --}}
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => route('front.cinema.show', ['slug' => $cinema->slug])],
            ['label' => __('front.reservation.heading'), 'url' => route('front.reservation.seats', ['id' => $screening->id])],
            ['label' => __('front.reservation.identify.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.reservation.identify.heading') }}</h1>

        <x-front.reservation.screening-summary :screening="$screening" :cinema="$cinema" class="mt-4" />

        <div class="mt-6">
            <livewire:front.reservation.identify :screening="$screening" />
        </div>
    </div>
</x-front.layout>
