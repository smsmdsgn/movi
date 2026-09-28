{{--
    マイページの無料鑑賞券の一覧の1件（P-05、7.14 構成要素1）。

    状態を持たない表示のため Blade コンポーネントとする（front-ui）。
    通常の一覧と、開閉（`<details>`）の中の残りの一覧の双方から呼ぶ（4.5.3
    「無料鑑賞券の一覧の件数」。旧12章 残課題42）。

    $ticket: FreeTicket
--}}
@props(['ticket'])
@php
    /** @var \App\Models\FreeTicket $ticket */
@endphp
<li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-stone-200 pb-2 last:border-b-0 last:pb-0">
    <span class="tabular-nums">{{ __('front.mypage.free_ticket.code') }}: {{ $ticket->code }}</span>
    <span class="tabular-nums text-stone-700">
        {{ __('front.mypage.free_ticket.expires_at') }}: {{ $ticket->expires_at->format('Y/n/j') }}
    </span>
</li>
