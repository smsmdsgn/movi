{{--
    予約照会（P-07、4.3.5 / 7.19）の Livewire ビュー。

    render() から渡る変数:
    - $selected: Reservation|null（照合して選択済みの予約。seats / screening を読み込み済み）
    - $seats: Collection<ReservationSeat>（座席表と同じ並び順。$selected が null なら空）
    - $candidates: Collection<Reservation>（複数件が該当した場合の一覧。それ以外は空）
    - $isCancelled: 選択中の予約がキャンセル済みか
    - $methodNumber / $methodContact: 照会方式の値（4.3.5 の方式A・B）
    - $fields: 照会フォームの入力欄（方式ごとの並びと現在値）
    - $weekdays: 曜日の表記
    - $confirmingCancel: この予約に対する確認を出しているか
    - $cancelledNotice: この予約のキャンセルが成立した場合の案内の文言キー（無ければ null）
    - $cancelError: この予約のキャンセルを承れなかった理由の文言キー（無ければ null）
    - $cancelRefundPending: キャンセルは成立したが返金が未了か（見出しと配色を分ける）
    - $cancelState: キャンセルの導線（4.4 / 7.19-8）。{available, noticeKey}、出さない場合は null

    **列挙型・クラス定数・入力欄の組み立ては `render()` から値で受け取る。** 先頭の
    PHP ブロックは docblock だけに留める（P-34・P-37 のビューと同じ扱い）。

    **Blade コメントの中にディレクティブの綴りを書かないこと。** コメントの除去より
    生PHPブロックの抽出が先に走るため、コメント内の綴りが本物の開始位置として拾われ、
    直後のブロックとルート要素がまとめて消える。

    画面は「フォーム」「一覧」「明細」の3状態を排他で出す。いずれも同じURLに留まる
    （照合の結果をURLへ載せると、共有された時点で照合を経ずに開けてしまう）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
@php
    /** @var \App\Models\Reservation|null $selected */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ReservationSeat> $seats */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Reservation> $candidates */
    /** @var array<int, array<string, string|null>> $fields */
@endphp
<div>
    {{-- ライブリージョンはルート直下に常設し、中身だけを差し替える（P-32〜P-34 と同じ扱い）。 --}}
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($cancelError !== null)
            {{-- キャンセルを承れなかった理由（4.4）。課金にも座席にも触れていない。 --}}
            <p class="mb-4 border border-red-700 bg-red-50 p-3 text-sm text-red-900">
                {{ __($cancelError) }}
            </p>
        @elseif ($errors->isNotEmpty())
            <p class="mb-4 border border-red-700 bg-red-50 p-3 text-sm text-red-900">
                {{ $errors->first() }}
            </p>
        @elseif ($searched && $selected === null && $candidates->isEmpty())
            {{-- 該当なしは利用者の操作の失敗とは限らないため中立の配色にする（20.3-2）。 --}}
            <p class="mb-4 border border-stone-300 bg-stone-100 p-3 text-sm">
                {{ __('front.lookup.not_found') }}
                @if ($method === $methodNumber)
                    <span class="mt-1 block text-stone-700">{{ __('front.lookup.not_found_hint') }}</span>
                @endif
            </p>
        @endif
    </div>

    @if ($selected !== null)
        @php
            $screening = $selected->screening;
            $cinema = $screening->booking->cinema;
        @endphp

        <h2 class="text-xl font-bold">{{ __('front.lookup.detail_heading') }}</h2>

        {{-- ご予約の状態。状態を色のみで区別せず、文言でも示す（5.2 / 18.2）。 --}}
        <p class="mt-3 inline-block border-2 px-3 py-1 text-sm font-bold {{ $isCancelled ? 'border-stone-400 bg-stone-100 text-stone-700' : 'border-red-800 text-red-900' }}">
            {{ __('front.lookup.status_heading') }}:
            {{ __('front.lookup.status.'.$selected->status->value) }}
        </p>

        @if ($isCancelled)
            {{-- 12章 残課題37。上映回はキャンセル後に変更されうるため、現在の値である旨を断る。 --}}
            <p class="mt-2 border border-stone-300 bg-stone-50 p-3 text-sm">
                {{ __('front.lookup.cancelled_note') }}
                @if ($selected->cancelled_at !== null)
                    <span class="mt-1 block tabular-nums text-stone-700">
                        {{ __('front.lookup.cancelled_at') }}: {{ $selected->cancelled_at->format('Y/n/j H:i') }}
                    </span>
                @endif
            </p>
        @elseif ($selected->isCheckedIn())
            <p class="mt-2 border border-stone-300 bg-stone-50 p-3 text-sm">{{ __('front.lookup.checked_in_note') }}</p>
        @endif

        <section aria-labelledby="lookup-no-heading" class="mt-6 border-2 border-stone-400 p-4">
            <h3 id="lookup-no-heading" class="text-sm font-bold">{{ __('front.lookup.reservation_no_heading') }}</h3>
            <p class="mt-1 text-3xl font-bold tabular-nums tracking-wider">{{ $selected->formattedReservationNo() }}</p>
        </section>

        <x-front.reservation.screening-summary :screening="$screening" :cinema="$cinema" class="mt-4" />

        <section aria-labelledby="lookup-seats-heading" class="mt-4 border border-stone-300 p-4">
            <h3 id="lookup-seats-heading" class="font-bold">
                {{ __('front.lookup.seats_heading') }}
                <span class="ml-1 text-sm font-normal text-stone-600">{{ __('front.lookup.count', ['count' => $seats->count()]) }}</span>
            </h3>

            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($seats as $row)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-stone-200 pb-2 last:border-b-0 last:pb-0">
                        <span class="font-bold tabular-nums">{{ $row->seat->displayName() }}</span>
                        <span>{{ $row->ticketType->name }}</span>
                        <span class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($row->amount)]) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- **小計と割引額は表示しない**（12章 残課題36-d。P-38 と同じ制約）。保存しているのは
             席ごとの確定額（割引適用後）と支払金額だけであり、割引前の金額を持たない。 --}}
        <section aria-labelledby="lookup-amount-heading" class="mt-4 border border-stone-300 p-4">
            <h3 id="lookup-amount-heading" class="font-bold">{{ __('front.lookup.amount_heading') }}</h3>

            <dl class="mt-3">
                <div class="flex items-baseline justify-between text-base font-bold">
                    <dt>{{ __('front.reservation.tickets.total') }}</dt>
                    <dd class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($selected->total_amount)]) }}</dd>
                </div>
            </dl>

            <p class="mt-2 text-xs text-stone-600">{{ __('front.lookup.amount_note') }}</p>
        </section>

        {{-- 入場用QRコード（4.3.5「表示内容」）は工程8で加える（12章 残課題18 / 36-a）。
             キャンセル済みの予約には入場の案内を出さない。 --}}
        @if (! $isCancelled)
            <section aria-labelledby="lookup-entry-heading" class="mt-4 border border-stone-300 bg-stone-50 p-4">
                <h3 id="lookup-entry-heading" class="font-bold">{{ __('front.lookup.entry_heading') }}</h3>
                <p class="mt-2 text-sm">{{ __('front.lookup.entry_pending') }}</p>
            </section>
        @endif

        {{-- 領収書（4.3.5「表示内容」）は画面設計が未確定のため案内のみ（12章 残課題36-b）。 --}}
        <section aria-labelledby="lookup-receipt-heading" class="mt-4 border border-stone-300 bg-stone-50 p-4">
            <h3 id="lookup-receipt-heading" class="font-bold">{{ __('front.lookup.receipt_heading') }}</h3>
            <p class="mt-2 text-sm">{{ __('front.lookup.receipt_pending') }}</p>
        </section>

        {{-- キャンセル（4.3.5「実行可能な操作」／4.4 / 7.19-8）。 --}}
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
                aria-labelledby="lookup-cancel-heading"
                class="mt-4 border-2 p-4 {{ $cancelRefundPending ? 'border-red-800 bg-red-50' : 'border-stone-400 bg-stone-50' }}"
            >
                <h3 id="lookup-cancel-heading" class="font-bold {{ $cancelRefundPending ? 'text-red-900' : '' }}">
                    {{ __($cancelRefundPending ? 'front.cancel.refund_pending_heading' : 'front.cancel.done_heading') }}
                </h3>
                <p class="mt-2 text-sm">{{ __($cancelledNotice) }}</p>
            </section>
        @elseif ($cancelState !== null)
            <section aria-labelledby="lookup-cancel-heading" class="mt-4 border border-stone-300 p-4">
                <h3 id="lookup-cancel-heading" class="font-bold">{{ __('front.cancel.heading') }}</h3>

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

        <div class="mt-6 flex flex-wrap gap-3">
            @if (count($matchedIds) > 1)
                <button type="button" wire:click="backToList" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                    {{ __('front.lookup.back_to_list') }}
                </button>
            @endif

            <button type="button" wire:click="startOver" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                {{ __('front.lookup.start_over') }}
            </button>
        </div>
    @elseif ($candidates->isNotEmpty())
        <h2 class="text-xl font-bold">{{ __('front.lookup.candidates_heading') }}</h2>
        <p class="mt-2 text-sm">{{ __('front.lookup.candidates_lead', ['count' => $candidates->count()]) }}</p>

        <ul class="mt-4 space-y-3">
            @foreach ($candidates as $candidate)
                @php $candidateStartsAt = $candidate->screening->starts_at; @endphp
                <li class="border border-stone-300 p-4">
                    <p class="font-bold">{{ $candidate->screening->booking->movie->title }}</p>
                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        <dt class="text-stone-600">{{ __('front.reservation.screening.cinema') }}</dt>
                        <dd>{{ $candidate->screening->booking->cinema->name }}</dd>

                        <dt class="text-stone-600">{{ __('front.reservation.screening.starts_at') }}</dt>
                        <dd class="tabular-nums">{{ __('front.reservation.screening.datetime', [
                            'date' => $candidateStartsAt->format('Y/n/j'),
                            'weekday' => $weekdays[$candidateStartsAt->dayOfWeek],
                            'time' => $candidateStartsAt->format('H:i'),
                        ]) }}</dd>

                        <dt class="text-stone-600">{{ __('front.lookup.reservation_no_heading') }}</dt>
                        <dd class="tabular-nums">{{ $candidate->formattedReservationNo() }}</dd>

                        <dt class="text-stone-600">{{ __('front.lookup.status_heading') }}</dt>
                        <dd>{{ __('front.lookup.status.'.$candidate->status->value) }}</dd>
                    </dl>

                    <button
                        type="button"
                        wire:click="select({{ $candidate->id }})"
                        class="mt-3 bg-red-800 px-4 py-3 text-sm font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                    >
                        {{ __('front.lookup.candidate_select') }}
                    </button>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">
            <button type="button" wire:click="startOver" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                {{ __('front.lookup.start_over') }}
            </button>
        </div>
    @else
        {{-- `wire:submit` で送る。Enter キーでの送信を拾え、ボタン以外の導線を足しても壊れない。 --}}
        <form wire:submit="search">
            <fieldset>
                <legend class="font-bold">{{ __('front.lookup.method_legend') }}</legend>

                <div class="mt-2 space-y-2">
                    @foreach ([$methodNumber => 'number', $methodContact => 'contact'] as $value => $key)
                        <label class="flex items-start gap-2 border p-3 {{ $method === $value ? 'border-red-800 bg-red-50' : 'border-stone-300' }}">
                            {{-- 方式の切り替えで入力欄が入れ替わるため `.live` とする。 --}}
                            <input
                                type="radio"
                                wire:model.live="method"
                                value="{{ $value }}"
                                @checked($method === $value)
                                class="mt-1 accent-red-800"
                            >
                            <span>
                                <span class="font-bold">{{ __('front.lookup.method.'.$key) }}</span>
                                <span class="mt-1 block text-sm text-stone-600">{{ __('front.lookup.method.'.$key.'_note') }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="mt-5 space-y-5">
                @foreach ($fields as $field)
                    @php
                        $property = $field['property'];
                        $inputId = 'lookup-'.$property;
                        $hintId = $inputId.'-hint';
                        $hasError = $errors->has($property);
                        $describedBy = collect([
                            $field['hint'] !== null ? $hintId : null,
                            $hasError ? $inputId.'-error' : null,
                        ])->filter()->implode(' ');
                    @endphp

                    <div>
                        <label for="{{ $inputId }}" class="block font-bold">
                            {{ __('front.lookup.fields.'.$property) }}
                            {{-- 必須は色と記号の双方で示す（13.5-5）。読み上げは aria-required が担う。 --}}
                            <span class="ml-1 align-middle text-xs text-red-800">※必須</span>
                        </label>

                        @if ($field['hint'] !== null)
                            <p id="{{ $hintId }}" class="mt-1 text-sm text-stone-600">
                                {{ __('front.lookup.hint.'.$field['hint']) }}
                            </p>
                        @endif

                        <input
                            id="{{ $inputId }}"
                            type="{{ $field['type'] }}"
                            wire:model="{{ $property }}"
                            value="{{ $field['value'] }}"
                            autocomplete="{{ $field['autocomplete'] }}"
                            @if ($field['inputmode'] !== null) inputmode="{{ $field['inputmode'] }}" @endif
                            @if ($field['placeholder'] !== null) placeholder="{{ __('front.lookup.placeholder.'.$field['placeholder']) }}" @endif
                            aria-required="true"
                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                            @if ($hasError) aria-invalid="true" @endif
                            class="mt-2 w-full border px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 {{ $hasError ? 'border-red-700 bg-red-50' : 'border-stone-400' }}"
                        >

                        @error($property)
                            <p id="{{ $inputId }}-error" class="mt-1 text-sm text-red-900">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                <button
                    type="submit"
                    class="bg-red-800 px-6 py-3 font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                >
                    {{ __('front.lookup.submit') }}
                </button>
            </div>
        </form>
    @endif
</div>
