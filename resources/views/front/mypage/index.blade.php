{{--
    マイページ（P-05、7.14）。

    $stampCount: 未交換のスタンプ数（4.5.1-2）
    $stampsPerTicket: 無料鑑賞券1枚に要するスタンプ数
    $freeTickets: Collection<FreeTicket>（使える券のみ。期限の近い順）
    $upcoming: Collection<Reservation>（これからの予約。上映開始の早い順）
    $history: LengthAwarePaginator<Reservation>（過去の予約。上映開始の新しい順）

    操作を伴わないため Livewire を用いない（13.4.3 / front-ui）。

    **館非依存ページのため、パンくずに館を挟まない**（19.3-9）。会員専用であり
    インデックス対象外（19.3-6）。
--}}
@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\FreeTicket> $freeTickets */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Reservation> $upcoming */
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Reservation> $history */
@endphp
<x-front.layout
    :title="__('front.mypage.title')"
    :description="__('front.mypage.description')"
    robots="noindex, nofollow"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => __('front.mypage.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.mypage.heading') }}</h1>
        <p class="mt-2 text-sm text-stone-600">{{ __('front.mypage.greeting', ['name' => auth()->user()->name]) }}</p>

        {{-- 7.14 構成要素1 スタンプカード --}}
        <section aria-labelledby="stamp-heading" class="mt-6 border border-stone-300 p-4">
            <h2 id="stamp-heading" class="font-bold">
                {{ __('front.mypage.stamp.heading') }}
                <span class="ml-1 text-sm font-normal text-stone-600">{{ __('front.mypage.stamp.count', ['count' => $stampCount]) }}</span>
            </h2>

            {{-- 個数を色のみで示さず、記号と読み上げ用の文言を併せる（5.2）。 --}}
            <ul class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('front.mypage.stamp.heading') }}">
                @for ($i = 1; $i <= $stampsPerTicket; $i++)
                    @php $earned = $i <= $stampCount; @endphp
                    <li
                        class="flex size-10 items-center justify-center border-2 text-lg {{ $earned ? 'border-red-800 bg-red-50 text-red-900' : 'border-dashed border-stone-400 text-stone-400' }}"
                        aria-label="{{ __($earned ? 'front.mypage.stamp.marker_earned' : 'front.mypage.stamp.marker_empty') }}"
                    >{{ $earned ? '★' : '☆' }}</li>
                @endfor
            </ul>

            <p class="mt-3 text-sm">
                @if ($stampCount >= $stampsPerTicket)
                    {{ __('front.mypage.stamp.ready') }}
                @else
                    {{ __('front.mypage.stamp.progress', ['remaining' => $stampsPerTicket - $stampCount]) }}
                @endif
            </p>
            <p class="mt-1 text-xs text-stone-600">{{ __('front.mypage.stamp.note', ['total' => $stampsPerTicket]) }}</p>
        </section>

        {{-- 7.14 構成要素1 無料鑑賞券 --}}
        <section aria-labelledby="free-ticket-heading" class="mt-4 border border-stone-300 p-4">
            <h2 id="free-ticket-heading" class="font-bold">
                {{ __('front.mypage.free_ticket.heading') }}
                <span class="ml-1 text-sm font-normal text-stone-600">{{ __('front.mypage.free_ticket.count', ['count' => $freeTickets->count()]) }}</span>
            </h2>

            @if ($freeTickets->isEmpty())
                <p class="mt-3 text-sm">{{ __('front.mypage.free_ticket.none') }}</p>
            @else
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($freeTickets as $ticket)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-stone-200 pb-2 last:border-b-0 last:pb-0">
                            <span class="tabular-nums">{{ __('front.mypage.free_ticket.code') }}: {{ $ticket->code }}</span>
                            <span class="tabular-nums text-stone-700">
                                {{ __('front.mypage.free_ticket.expires_at') }}: {{ $ticket->expires_at->format('Y/n/j') }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                {{-- 12章 残課題31。券を選ぶ画面が無いため、使い道を約束しない。 --}}
                <p class="mt-3 border border-stone-300 bg-stone-50 p-3 text-xs">{{ __('front.mypage.free_ticket.pending') }}</p>
            @endif
        </section>

        {{-- 7.14 構成要素2 これからの予約 --}}
        <section aria-labelledby="upcoming-heading" class="mt-6">
            <h2 id="upcoming-heading" class="text-xl font-bold">{{ __('front.mypage.upcoming.heading') }}</h2>

            @if ($upcoming->isEmpty())
                <p class="mt-3 text-sm">{{ __('front.mypage.upcoming.none') }}</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($upcoming as $reservation)
                        <x-front.mypage.reservation-card :reservation="$reservation" />
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- 7.14 構成要素3 過去の予約（ページネーション） --}}
        <section aria-labelledby="history-heading" class="mt-6">
            <h2 id="history-heading" class="text-xl font-bold">{{ __('front.mypage.history.heading') }}</h2>

            @if ($history->isEmpty())
                <p class="mt-3 text-sm">{{ __('front.mypage.history.none') }}</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($history as $reservation)
                        <x-front.mypage.reservation-card :reservation="$reservation" />
                    @endforeach
                </ul>

                {{-- フレームワーク同梱の `links()` は使わない（`sm:`・英語・配色が
                     いずれも規約と合わない。13.5-2 / 20.1-3 / 18.2）。 --}}
                <x-front.pagination :paginator="$history" />
            @endif
        </section>

        {{-- 7.14 構成要素4・5 --}}
        <section aria-labelledby="account-heading" class="mt-6 border border-stone-300 p-4">
            <h2 id="account-heading" class="font-bold">{{ __('front.mypage.account.heading') }}</h2>

            <p class="mt-3">
                <a href="{{ route('profile.edit') }}" class="inline-block border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                    {{ __('front.mypage.account.edit') }}
                </a>
            </p>

            <h3 class="mt-4 text-sm font-bold">{{ __('front.mypage.account.withdrawal_heading') }}</h3>
            <p class="mt-1 text-sm text-stone-700">{{ __('front.mypage.account.withdrawal') }}</p>
        </section>
    </div>
</x-front.layout>
