{{--
    予約キャンセルの導線（4.4 / 7.19-8）。**予約照会（P-07）とマイページの予約詳細
    （P-06、7.14）が共有する。**

    **Livewire コンポーネントのビューの中でのみ使う。** `wire:click` が呼ぶ
    `startCancel` / `abortCancel` / `cancel` は `App\Livewire\Front\Concerns\
    CancelsReservation` が持つ（メソッド名をトレイトと揃えているため、使う側は
    トレイトを use するだけでよい）。

    $cancelState: array{available: bool, noticeKey: string|null}|null
        （null は節ごと出さない場合。キャンセル済み）
    $confirmingCancel: この予約に対する確認を出しているか
    $cancelledNotice: キャンセルが成立した場合の案内の文言キー（無ければ null）
    $refundPending: キャンセルは成立したが返金が未了か（見出しと配色を分ける）
    $idPrefix: 見出しのIDの接頭辞
--}}
@props(['cancelState', 'confirmingCancel', 'cancelledNotice', 'refundPending', 'idPrefix'])
@php
    /** @var array{available: bool, noticeKey: string|null}|null $cancelState */
@endphp
@if ($cancelledNotice !== null)
    {{-- 成立した後。導線を消し、結果だけを残す。

         **返金が未了の場合は見出しと配色を分ける。** 同じ見た目で出すと、劇場への
         連絡が要る状態と、何もしなくてよい状態が区別できない（4.3.18）。

         `role="status"` を付けるのは、返金を伴う取り消せない操作の直後に押した
         ボタンが消えるため、読み上げ環境で結果が伝わらないため（4.3.10 が P-32 で
         定めた「案内はライブリージョンへ流す」方針に揃える）。 --}}
    <section
        role="status"
        aria-live="polite"
        aria-labelledby="{{ $idPrefix }}-cancel-heading"
        {{ $attributes->merge(['class' => 'mt-4 border-2 p-4 '.($refundPending ? 'border-red-800 bg-red-50' : 'border-stone-400 bg-stone-50')]) }}
    >
        <h3 id="{{ $idPrefix }}-cancel-heading" class="font-bold {{ $refundPending ? 'text-red-900' : '' }}">
            {{ __($refundPending ? 'front.cancel.refund_pending_heading' : 'front.cancel.done_heading') }}
        </h3>
        <p class="mt-2 text-sm">{{ __($cancelledNotice) }}</p>
    </section>
@elseif ($cancelState !== null)
    <section aria-labelledby="{{ $idPrefix }}-cancel-heading" {{ $attributes->merge(['class' => 'mt-4 border border-stone-300 p-4']) }}>
        <h3 id="{{ $idPrefix }}-cancel-heading" class="font-bold">{{ __('front.cancel.heading') }}</h3>

        @if (! $cancelState['available'])
            {{-- 期限切れ・入場済み。理由を示し、ボタンは出さない。 --}}
            <p class="mt-2 text-sm">{{ __($cancelState['noticeKey']) }}</p>
        @elseif ($confirmingCancel)
            {{-- 取り消せない操作のため確認を1段挟む（4.3.18）。 --}}
            <p class="mt-2 font-bold text-red-900">{{ __('front.cancel.confirm_heading') }}</p>
            <p class="mt-1 text-sm">{{ __('front.cancel.confirm_lead') }}</p>
            <p class="mt-1 text-sm">{{ __('front.cancel.refund_note') }}</p>

            <div class="mt-4 flex flex-wrap gap-3">
                {{-- 二重送信を抑える。サーバー側は `status` の判定で2本目を止めるため
                     金銭は安全だが、2本目の応答が「承れません」を描き、成立したのに
                     失敗したように見える（P-37 の確定ボタンと同じ手当て）。 --}}
                <button
                    type="button"
                    wire:click="cancel"
                    wire:loading.attr="disabled"
                    wire:target="cancel"
                    class="bg-red-800 px-6 py-3 font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                >
                    {{ __('front.cancel.submit') }}
                </button>

                <button type="button" wire:click="abortCancel" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                    {{ __('front.cancel.abort') }}
                </button>
            </div>
        @else
            <p class="mt-2 text-sm">{{ __('front.cancel.lead') }}</p>
            <p class="mt-1 text-sm text-stone-600">{{ __('front.cancel.refund_note') }}</p>

            <button
                type="button"
                wire:click="startCancel"
                class="mt-3 border-2 border-red-800 px-4 py-3 text-sm font-bold text-red-900 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
            >
                {{ __('front.cancel.start') }}
            </button>
        @endif
    </section>
@endif
