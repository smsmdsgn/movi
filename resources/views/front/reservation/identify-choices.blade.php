{{--
    会員／非会員の選択（P-33、7.8）の Livewire ビュー。

    render() から渡る変数:
    - $onSale: 販売期間内の上映回が存在するか（4.3.1 / 4.3.10）
    - $hasSeats: 座席を保持しているか。保持していなければ選択肢を出さない
    - $noticeKey: 表示する案内の文言キー（7.17）。無い場合は null
    - $seatsUrl: 座席選択（P-31）のURL

    ライブリージョンはルート直下に常設し、中身だけを差し替える（P-32 と同じ扱い。4.3.10）。

    7.8-2「会員登録して購入」は P-02（会員登録）が未実装のため出さない（4.3.11、12章 残課題26）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
<div>
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($noticeKey !== null)
            {{-- 販売期間外・削除済みの回は利用者の操作の失敗ではないため中立の配色にする（4.3.10）。 --}}
            <p class="mb-4 border p-3 text-sm {{ $onSale ? 'border-red-700 bg-red-50 text-red-900' : 'border-stone-300 bg-stone-100' }}">
                {{ __($noticeKey) }}
            </p>
        @endif
    </div>

    @if ($onSale && ! $hasSeats)
        <p>
            <a href="{{ $seatsUrl }}" class="inline-block border border-stone-400 px-4 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
                {{ __('front.reservation.back_to_seats') }}
            </a>
        </p>
    @elseif ($onSale)
        <div class="grid gap-4 md:grid-cols-2">
            {{-- 7.8-1 会員としてログイン --}}
            <section aria-labelledby="member-heading" class="flex flex-col border border-stone-300 p-4">
                <h2 id="member-heading" class="font-bold">{{ __('front.reservation.identify.member.heading') }}</h2>
                <p class="mt-2 text-sm text-stone-600">{{ __('front.reservation.identify.member.lead') }}</p>

                {{-- 会員特典の説明（7.8）。会員として購入する動機づけとして併記する。 --}}
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach (__('front.reservation.identify.member.benefits') as $benefit)
                        <li class="flex gap-2">
                            <span aria-hidden="true" class="text-brand">●</span>
                            <span>{{ $benefit }}</span>
                        </li>
                    @endforeach
                </ul>

                {{-- `transfer()` は移譲先が別のブラウザで保持しているロックを削除する（4.3.8）。
                     座席を失う可能性があるため、ログインの前に知らせる。 --}}
                <p class="mt-3 border border-stone-300 bg-stone-100 p-2 text-xs text-stone-700">
                    {{ __('front.reservation.identify.member.transfer_note') }}
                </p>

                <div class="mt-4 grow content-end">
                    <button
                        type="button"
                        wire:click="login"
                        class="w-full bg-red-800 px-6 py-3 font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                    >
                        {{ __('front.reservation.identify.member.action') }}
                    </button>
                </div>
            </section>

            {{-- 7.8-3 会員登録せずに購入 --}}
            <section aria-labelledby="guest-heading" class="flex flex-col border border-stone-300 p-4">
                <h2 id="guest-heading" class="font-bold">{{ __('front.reservation.identify.guest.heading') }}</h2>
                <p class="mt-2 text-sm text-stone-600">{{ __('front.reservation.identify.guest.lead') }}</p>

                <ul class="mt-3 space-y-2 text-sm">
                    @foreach (__('front.reservation.identify.guest.notes') as $note)
                        <li class="flex gap-2">
                            <span aria-hidden="true" class="text-stone-500">●</span>
                            <span>{{ $note }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-4 grow content-end">
                    <button
                        type="button"
                        wire:click="continueAsGuest"
                        class="w-full border-2 border-red-800 px-6 py-3 font-bold text-red-900 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                    >
                        {{ __('front.reservation.identify.guest.action') }}
                    </button>
                </div>
            </section>
        </div>

        <div class="mt-6">
            <a href="{{ $seatsUrl }}" class="inline-block border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                {{ __('front.reservation.back_to_seats') }}
            </a>
        </div>
    @endif
</div>
