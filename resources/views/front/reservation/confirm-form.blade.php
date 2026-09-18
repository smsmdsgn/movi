{{--
    予約確認（P-37、7.12）の Livewire ビュー。

    render() から渡る変数:
    - $onSale / $canProceed / $noticeKey / $recoveryUrl / $recoveryLabelKey: 前提の判定（4.3.12）
    - $breakdown: PriceBreakdown。前提が崩れている場合は null
    - $seats: 保持中の座席（座席表と同じ並び順）
    - $ticketTypes: 券種マスタ（券種名の表示に使う）
    - $purchaser: 購入者情報（name / email / phone）
    - $holdExpiresAt: 座席ロックの期限（7.12-5）。表示はブラウザが刻む
    - $publishableKey: Stripe の公開可能キー（追加認証に使う）
    - $seatsLost: 返金を伴う失敗（8.2）。座席選択からやり直す
    - $seatsUrl / $paymentUrl: 復帰先

    **確定ボタンが課金と予約確定を1つの操作として行う**（4.3.14）。追加認証（3Dセキュア）が
    必要な場合はサーバーが `payment-authentication-required` を発行し、ブラウザが
    `handleNextAction()` を実行したのち `completeAuthentication()` を呼び戻す。
    **認証の成否はサーバーが PaymentIntent を取り直して判断する**（17.3-3）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
@php
    /** @var \App\Services\PriceBreakdown|null $breakdown */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Seat> $seats */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\TicketType> $ticketTypes */
    /** @var \Carbon\CarbonImmutable|null $holdExpiresAt */
@endphp
<div
    x-data="{
        processing: false,
        stripe: null,
        init() {
            if (typeof Stripe !== 'undefined' && @js($publishableKey) !== '') {
                this.stripe = Stripe(@js($publishableKey));
            }
        },
        async submit() {
            if (this.processing) {
                return;
            }

            this.processing = true;
            await $wire.confirm();
            this.processing = false;
        },
        async authenticate(clientSecret) {
            this.processing = true;

            if (this.stripe !== null) {
                // 結果は見ない。成否はサーバーが PaymentIntent を取り直して判断する。
                await this.stripe.handleNextAction({ clientSecret });
            }

            await $wire.completeAuthentication();
            this.processing = false;
        },
    }"
    x-on:payment-authentication-required.window="authenticate($event.detail.clientSecret)"
>
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($noticeKey !== null)
            <p class="mb-4 border p-3 text-sm {{ $onSale ? 'border-red-700 bg-red-50 text-red-900' : 'border-stone-300 bg-stone-100' }}">
                {{ __($noticeKey) }}
            </p>
        @endif
    </div>

    @if ($seatsLost)
        {{-- 課金の成立後に座席を確保できなかった場合（8.2）。返金は済んでいる。 --}}
        <p>
            <a href="{{ $seatsUrl }}" class="inline-block border border-stone-400 px-4 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
                {{ __('front.reservation.back_to_seats') }}
            </a>
        </p>
    @elseif (! $canProceed)
        @if ($recoveryUrl !== null)
            <p>
                <a href="{{ $recoveryUrl }}" class="inline-block border border-stone-400 px-4 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
                    {{ __($recoveryLabelKey) }}
                </a>
            </p>
        @endif
    @else
        {{-- 7.12-5 座席ロックの残り時間。期限を渡し、表示はブラウザが1秒ごとに刻む。
             期限を過ぎたら確定を試みず、座席選択からのやり直しを促す（確定時もサーバーが
             同じ判定を行う。8.2 手順1）。 --}}
        @if ($holdExpiresAt !== null)
            <section
                aria-labelledby="hold-heading"
                class="border border-stone-300 bg-stone-50 p-4"
                x-data="{
                    remaining: 0,
                    timer: null,
                    init() {
                        this.tick();
                        this.timer = setInterval(() => this.tick(), 1000);
                    },
                    destroy() {
                        clearInterval(this.timer);
                    },
                    tick() {
                        this.remaining = Math.max(0, Math.floor((Date.parse(@js($holdExpiresAt->toIso8601String())) - Date.now()) / 1000));
                    },
                    get label() {
                        const minutes = Math.floor(this.remaining / 60);
                        const seconds = String(this.remaining % 60).padStart(2, '0');

                        return `${minutes}:${seconds}`;
                    },
                }"
            >
                <h2 id="hold-heading" class="text-sm font-bold">{{ __('front.reservation.confirm.hold_heading') }}</h2>
                <p class="mt-1 text-sm" aria-live="off">
                    <span x-text="label" class="font-bold tabular-nums">--:--</span>
                    <span>{{ __('front.reservation.confirm.hold_note') }}</span>
                </p>
                <p x-cloak x-show="remaining === 0" class="mt-2 text-sm text-red-900">
                    {{ __('front.reservation.errors.lock_expired') }}
                </p>
            </section>
        @endif

        {{-- 7.12-2 座席と券種の一覧 --}}
        <section aria-labelledby="seats-heading" class="mt-4 border border-stone-300 p-4">
            <h2 id="seats-heading" class="font-bold">{{ __('front.reservation.confirm.seats_heading') }}</h2>

            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($breakdown->seats as $seatPrice)
                    @php
                        $seat = $seats->firstWhere('id', $seatPrice->seatId);
                        $ticketType = $ticketTypes->firstWhere('id', $seatPrice->ticketTypeId);
                    @endphp
                    <li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-stone-200 pb-2 last:border-b-0 last:pb-0">
                        <span class="font-bold tabular-nums">{{ $seat?->displayName() }}</span>
                        <span>{{ $ticketType?->name }}</span>
                        <span class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($seatPrice->amount())]) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- 7.12-3 支払金額の内訳 --}}
        <section aria-labelledby="amount-heading" class="mt-4 border border-stone-300 p-4">
            <h2 id="amount-heading" class="font-bold">{{ __('front.reservation.confirm.amount_heading') }}</h2>

            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt>{{ __('front.reservation.tickets.subtotal') }}</dt>
                    <dd class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($breakdown->subtotal())]) }}</dd>
                </div>

                @if ($breakdown->discount !== null)
                    <div class="flex justify-between text-red-900">
                        <dt>{{ $breakdown->discount->label() }}</dt>
                        <dd class="tabular-nums">−{{ __('front.reservation.yen', ['amount' => number_format($breakdown->discountAmount())]) }}</dd>
                    </div>
                @endif

                <div class="flex justify-between border-t border-stone-300 pt-2 text-base font-bold">
                    <dt>{{ __('front.reservation.tickets.total') }}</dt>
                    <dd class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($breakdown->total())]) }}</dd>
                </div>
            </dl>

            @if ($breakdown->isFullyCovered())
                {{-- 4.5.2「決済のスキップ条件」。決済画面を通っていないことを明示する。 --}}
                <p class="mt-3 text-xs text-stone-600">{{ __('front.reservation.confirm.no_payment_note') }}</p>
            @endif
        </section>

        {{-- 7.12-4 購入者情報 --}}
        <section aria-labelledby="purchaser-heading" class="mt-4 border border-stone-300 p-4">
            <h2 id="purchaser-heading" class="font-bold">{{ __('front.reservation.confirm.purchaser_heading') }}</h2>

            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex flex-wrap gap-2">
                    <dt class="w-32 shrink-0 text-stone-600">{{ __('front.reservation.customer.fields.name') }}</dt>
                    <dd>{{ $purchaser['name'] }}</dd>
                </div>
                <div class="flex flex-wrap gap-2">
                    <dt class="w-32 shrink-0 text-stone-600">{{ __('front.reservation.customer.fields.email') }}</dt>
                    <dd class="break-all">{{ $purchaser['email'] }}</dd>
                </div>
                <div class="flex flex-wrap gap-2">
                    <dt class="w-32 shrink-0 text-stone-600">{{ __('front.reservation.customer.fields.phone') }}</dt>
                    <dd class="tabular-nums">{{ $purchaser['phone'] }}</dd>
                </div>
            </dl>
        </section>

        <p class="mt-4 text-xs text-stone-600">{{ __('front.reservation.confirm.no_change_note') }}</p>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ $paymentUrl }}" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                {{ __('front.reservation.back_to_payment') }}
            </a>

            <div class="flex items-center gap-3">
                <span x-cloak x-show="processing" aria-live="polite" class="text-sm text-stone-600">
                    {{ __('front.reservation.confirm.processing') }}
                </span>

                {{-- `disabled` を付けない（P-31・P-36 と同じ判断。4.3.14）。同一画面での
                     二度押しは Alpine の `processing` で抑える。**別タブ・別端末からの
                     同時確定は防げない**ため、6.4.2 の一意制約が最終防波堤となり、
                     後から確定した側は返金される（4.3.15）。 --}}
                <button
                    type="button"
                    x-on:click="submit()"
                    x-bind:aria-disabled="processing"
                    x-bind:class="processing ? '!bg-stone-400 cursor-progress' : 'hover:bg-red-900'"
                    class="bg-red-800 px-6 py-3 font-bold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                >
                    {{ __('front.reservation.confirm.submit') }}
                </button>
            </div>
        </div>
    @endif
</div>
