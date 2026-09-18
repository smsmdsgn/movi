{{--
    予約確認（P-37、7.12）。内容の確認と確定は Livewire コンポーネントが担い、
    本ビューは共通レイアウト・メタ情報・上映情報を組み立てる（P-36 と同じ分担）。

    $screening: Screening（booking.movie / booking.format / theater を読み込み済み）
    $cinema: Cinema（上映回から定まる館。`CurrentCinemaService::remember()` が確定させたもの）
--}}
@php
    /** @var \App\Models\Screening $screening */
    /** @var \App\Models\Cinema $cinema */
@endphp
<x-front.layout
    :title="__('front.reservation.confirm.title', ['movie' => $screening->booking->movie->title, 'cinema' => $cinema->name])"
    :description="__('front.reservation.confirm.description')"
    robots="noindex, nofollow"
>
    {{-- 追加認証（3Dセキュア）を `handleNextAction()` で処理するため、確定ボタンを持つ
         本画面でも Stripe.js を読み込む（8.2 / 4.3.15）。 --}}
    @push('head')
        <script src="https://js.stripe.com/v3/" defer></script>
    @endpush

    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => $cinema->name, 'url' => route('front.cinema.show', ['slug' => $cinema->slug])],
            ['label' => __('front.reservation.heading'), 'url' => route('front.reservation.seats', ['id' => $screening->id])],
            ['label' => __('front.reservation.confirm.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.reservation.confirm.heading') }}</h1>

        {{-- 7.12-1 劇場名・作品名・シアター・上映日時 --}}
        <x-front.reservation.screening-summary :screening="$screening" :cinema="$cinema" class="mt-4" />

        <div class="mt-6">
            <livewire:front.reservation.confirm :screening="$screening" />
        </div>
    </div>
</x-front.layout>
