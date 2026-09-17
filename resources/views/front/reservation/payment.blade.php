{{--
    決済（P-36、7.11）。支払方法の選択とカード情報の入力は Livewire コンポーネントが担い、
    本ビューは共通レイアウト・メタ情報・上映情報を組み立てる（P-35 と同じ分担）。

    $screening: Screening（booking.movie / booking.format / theater を読み込み済み）
    $cinema: Cinema（上映回から定まる館。`CurrentCinemaService::remember()` が確定させたもの）
--}}
@php
    /** @var \App\Models\Screening $screening */
    /** @var \App\Models\Cinema $cinema */
@endphp
<x-front.layout
    :title="__('front.reservation.payment.title', ['movie' => $screening->booking->movie->title, 'cinema' => $cinema->name])"
    :description="__('front.reservation.payment.description')"
    robots="noindex, nofollow"
>
    {{-- Stripe.js は Stripe の配信元から読み込む（8.2 / 17.7 のCSP `script-src`）。
         npm で同梱すると、カード情報の入力欄が自サイトのコードとして扱われ、
         17.3-1「自システムで保持・中継しない」の前提が崩れる。 --}}
    @push('head')
        <script src="https://js.stripe.com/v3/" defer></script>
    @endpush

    <div class="mx-auto max-w-3xl px-4 py-6">
        {{-- 予約フローは館配下のURLではないが、戻り先を示すため館までの経路を出す（19.3-9）。
             インデックス対象外のため BreadcrumbList の構造化データは付けない。 --}}
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => route('front.cinema.show', ['slug' => $cinema->slug])],
            ['label' => __('front.reservation.heading'), 'url' => route('front.reservation.seats', ['id' => $screening->id])],
            ['label' => __('front.reservation.payment.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.reservation.payment.heading') }}</h1>

        <x-front.reservation.screening-summary :screening="$screening" :cinema="$cinema" class="mt-4" />

        <div class="mt-6">
            <livewire:front.reservation.payment :screening="$screening" />
        </div>
    </div>
</x-front.layout>
