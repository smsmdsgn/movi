{{--
    同意画面（P-32、7.7 / 4.3.7）の Livewire ビュー。

    render() から渡る変数:
    - $onSale: 販売期間内の上映回が存在するか（4.3.1 / 4.3.10）
    - $seats: 保持中（選択中）の座席。空の場合は同意を求めず座席選択へ戻す導線を出す
    - $noticeKey: 表示する案内の文言キー（7.17）。無い場合は null
    - $maxSeats: 1度に選択できる座席数の上限。P-32 が現在出す文言に `:max` は無いが、
      P-31 と同じ形で渡す（7.17 の文言を増やしたときの置換漏れを防ぐ）
    - $seatsUrl: 座席選択（P-31）のURL
    - $termsUrl: 利用規約の全文（P-16、4.3.7-6）

    ライブリージョンはルート直下に常設し、中身だけを差し替える。分岐ごとに要素を
    出し入れすると、後から挿入されたリージョンを読み上げない実装がある（P-31 と同じ扱い）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Seat> $seats */
@endphp
<div>
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($noticeKey !== null)
            {{-- 販売期間外・削除済みの回は利用者の操作の失敗ではないため、P-31 と同じ中立の配色にする。
                 同じ文言が画面によって「お知らせ」と「エラー」に見え分かれないようにする。 --}}
            <p class="mb-4 border p-3 text-sm {{ $onSale ? 'border-red-700 bg-red-50 text-red-900' : 'border-stone-300 bg-stone-100' }}">
                {{ __($noticeKey, ['max' => $maxSeats]) }}
            </p>
        @endif
    </div>

    {{-- 販売できない回（削除済み・販売期間外、4.3.10）では案内のみを出し、操作を残さない。 --}}
    @if ($onSale && $seats->isEmpty())
        {{-- 正規の経路（P-31 の「次へ進む」）では1席以上を保持している。ここに至るのは
             ロックの期限切れ・別の上映回の選択・URLへの直接到達のいずれかであり、
             利用者の次の行動はいずれも「座席の選択からやり直す」で同じ（4.3.10）。 --}}
        <p>
            <a href="{{ $seatsUrl }}" class="inline-block border border-stone-400 px-4 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
                {{ __('front.reservation.agreement.back') }}
            </a>
        </p>
    @elseif ($onSale)
        {{-- 選択した座席と枚数（4.3.7-5） --}}
        <section aria-labelledby="selected-seats-heading" class="border border-stone-300 p-3">
            <h2 id="selected-seats-heading" class="text-sm text-stone-600">{{ __('front.reservation.agreement.seats_heading') }}</h2>
            <p class="mt-1">
                <span class="font-bold tabular-nums">{{ $seats->map(fn ($seat) => $seat->displayName())->implode('・') }}</span>
                <span class="tabular-nums">（{{ __('front.reservation.selected.count', ['count' => $seats->count()]) }}）</span>
            </p>
        </section>

        {{-- 利用規約の要約と全文へのリンク（4.3.7-6） --}}
        <section aria-labelledby="terms-heading" class="mt-6 border border-stone-300 p-4">
            <h2 id="terms-heading" class="font-bold">{{ __('front.reservation.agreement.terms_heading') }}</h2>
            <p class="mt-2 text-sm text-stone-600">{{ __('front.reservation.agreement.terms_note') }}</p>

            <ul class="mt-3 space-y-2 text-sm">
                @foreach (__('front.reservation.agreement.terms') as $term)
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-brand">●</span>
                        <span>{{ $term }}</span>
                    </li>
                @endforeach
            </ul>

            <p class="mt-4 text-sm">
                <a href="{{ $termsUrl }}" target="_blank" rel="noopener" class="underline decoration-stone-400 hover:text-red-800">
                    {{ __('front.reservation.agreement.terms_link') }}
                </a>
            </p>
        </section>

        {{-- 同意チェックボックス（4.3.7-7）。未チェックのままボタンを操作不可にするのではなく、
             押した時点で 7.17 の文言を出す（操作できないボタンは理由を伝えられない）。 --}}
        <div class="mt-6">
            <label class="flex items-center gap-3 border border-stone-300 p-4 hover:bg-stone-50">
                <input
                    type="checkbox"
                    wire:model="agreed"
                    class="h-5 w-5 accent-red-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                >
                <span class="font-bold">{{ __('front.reservation.agreement.agree') }}</span>
            </label>
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ $seatsUrl }}" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                {{ __('front.reservation.agreement.back') }}
            </a>

            <button
                type="button"
                wire:click="proceed"
                class="bg-red-800 px-6 py-3 font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
            >
                {{ __('front.reservation.agreement.proceed') }}
            </button>
        </div>
    @endif
</div>
