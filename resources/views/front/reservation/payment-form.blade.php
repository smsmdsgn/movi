{{--
    決済（P-36、7.11）の Livewire ビュー。

    render() から渡る変数:
    - $onSale: 販売期間内の上映回が存在するか（4.3.1 / 4.3.10）
    - $canProceed: 先へ進める前提（販売期間・座席・同意・お客様情報・券種）が揃っているか
    - $noticeKey: 表示する案内の文言キー（7.17）。無い場合は null
    - $recoveryUrl / $recoveryLabelKey: 前提を満たしていない場合の復帰先（4.3.12）
    - $total: 支払金額（円）。前提が揃っていない場合は null
    - $stripeConfigured: Stripe のキーが設定されているか（15.1）
    - $publishableKey: 公開可能キー。ブラウザへ渡す前提の値（17.9-1）
    - $ticketsUrl: 券種選択（P-35）のURL。戻り先

    **カード情報はこのフォームを通らない。** 入力欄は Stripe Elements が iframe として
    差し込むものであり、値はブラウザから Stripe へ直接送られる（8.2 / 17.3-1）。本画面が
    サーバーへ渡すのは PaymentMethod のID（`pm_...`）だけである。

    ライブリージョンはルート直下に常設し、中身だけを差し替える（P-32〜P-35 と同じ扱い）。

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

    @if (! $canProceed)
        {{-- 前提を満たしていない場合は入力を求めず、復帰先だけを残す（4.3.12）。 --}}
        @if ($recoveryUrl !== null)
            <p>
                <a href="{{ $recoveryUrl }}" class="inline-block border border-stone-400 px-4 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
                    {{ __($recoveryLabelKey) }}
                </a>
            </p>
        @endif
    @else
        {{-- 支払金額は P-35 と同じ計算による（13.4.5）。内訳は予約確認（P-37）で示す。 --}}
        @if ($total !== null)
            <section aria-labelledby="amount-heading" class="border border-stone-300 p-4">
                <h2 id="amount-heading" class="font-bold">{{ __('front.reservation.payment.amount_heading') }}</h2>
                <p class="mt-2 text-2xl font-bold tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($total)]) }}</p>
            </section>
        @endif

        @if (! $stripeConfigured)
            {{-- キー未設定（15.1）。利用者の操作では解消しないため、カードの入力欄を出さない。 --}}
            <p class="mt-6 border border-stone-300 bg-stone-100 p-3 text-sm">
                {{ __('front.reservation.payment.errors.unavailable') }}
            </p>
        @else
            <noscript>
                <p class="mt-6 border border-stone-300 bg-stone-100 p-3 text-sm">
                    {{ __('front.reservation.payment.no_script') }}
                </p>
            </noscript>

            {{-- 支払方法の選択（7.11.1）は Alpine のみで完結させる。クレジットカード以外は
                 案内を出すだけで先へ進めず、サーバーが保持すべき状態にならない。 --}}
            <div
                x-data="{
                    method: 'card',
                    processing: false,
                    cardError: '',
                    stripe: null,
                    card: null,
                    mountCard() {
                        if (typeof Stripe === 'undefined') {
                            this.cardError = @js(__('front.reservation.payment.errors.unavailable'));

                            return;
                        }

                        this.stripe = Stripe(@js($publishableKey));
                        this.card = this.stripe.elements().create('card', { hidePostalCode: true });
                        this.card.mount($refs.cardElement);
                        this.card.on('change', (event) => {
                            this.cardError = event.error ? event.error.message : '';
                        });
                    },
                    async pay() {
                        if (this.processing || this.method !== 'card' || this.card === null) {
                            return;
                        }

                        this.processing = true;
                        this.cardError = '';

                        const result = await this.stripe.createPaymentMethod({ type: 'card', card: this.card });

                        if (result.error) {
                            this.cardError = result.error.message ?? '';
                            this.processing = false;

                            return;
                        }

                        // サーバーは受け取ったIDを Stripe へ問い合わせて確かめる（17章）。
                        await $wire.preparePayment(result.paymentMethod.id);
                        this.processing = false;
                    },
                }"
                x-init="mountCard()"
                class="mt-6"
            >
                <fieldset class="border border-stone-300 p-4">
                    <legend class="px-1 font-bold">{{ __('front.reservation.payment.method_heading') }}</legend>

                    <div class="space-y-2">
                        @foreach (['card', 'hoge_pay', 'fuga_pay', 'mogo_pay'] as $method)
                            <label class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="payment-method"
                                    value="{{ $method }}"
                                    x-model="method"
                                    class="size-4 accent-red-800"
                                >
                                <span>{{ __('front.reservation.payment.methods.'.$method) }}</span>
                            </label>
                        @endforeach
                    </div>

                    {{-- 7.11.1 クレジットカード以外を選択した場合の案内。 --}}
                    <p
                        x-cloak
                        x-show="method !== 'card'"
                        class="mt-3 border border-stone-400 bg-stone-100 p-3 text-sm"
                    >
                        {{ __('front.reservation.payment.card_only') }}
                    </p>
                </fieldset>

                <section x-show="method === 'card'" aria-labelledby="card-heading" class="mt-4 border border-stone-300 p-4">
                    <h2 id="card-heading" class="font-bold">{{ __('front.reservation.payment.card_heading') }}</h2>
                    <p class="mt-1 text-sm text-stone-600">{{ __('front.reservation.payment.lead') }}</p>

                    {{-- Stripe Elements が iframe を差し込む位置。Livewire の再描画で
                         差し替えられると入力中のカード情報ごと消えるため wire:ignore を付ける。 --}}
                    <div
                        wire:ignore
                        x-ref="cardElement"
                        role="group"
                        aria-label="{{ __('front.reservation.payment.card_label') }}"
                        class="mt-3 border border-stone-400 p-3"
                    ></div>

                    {{-- Stripe が返す入力の誤り。色だけで区別しない（13.5-5）ため文言で示す。 --}}
                    <p
                        x-cloak
                        x-show="cardError !== ''"
                        x-text="cardError"
                        role="alert"
                        aria-live="assertive"
                        class="mt-2 border border-red-700 bg-red-50 p-2 text-sm text-red-900"
                    ></p>
                </section>

                {{-- テストカードの案内（7.11.2） --}}
                <section aria-labelledby="test-cards-heading" class="mt-4 border border-stone-300 bg-stone-50 p-4">
                    <h2 id="test-cards-heading" class="text-sm font-bold">{{ __('front.reservation.payment.test_cards.heading') }}</h2>
                    <p class="mt-2 text-xs text-stone-700">{{ __('front.reservation.payment.test_cards.note') }}</p>
                    <ul class="mt-2 space-y-1 text-xs text-stone-700">
                        <li class="tabular-nums">{{ __('front.reservation.payment.test_cards.success') }}</li>
                        <li class="tabular-nums">{{ __('front.reservation.payment.test_cards.declined') }}</li>
                        <li>{{ __('front.reservation.payment.test_cards.other') }}</li>
                    </ul>
                </section>

                <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                    <a href="{{ $ticketsUrl }}" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                        {{ __('front.reservation.back_to_tickets') }}
                    </a>

                    <div class="flex items-center gap-3">
                        <span x-cloak x-show="processing" aria-live="polite" class="text-sm text-stone-600">
                            {{ __('front.reservation.payment.processing') }}
                        </span>

                        {{-- `disabled` を付けない。`disabled` な button はフォーカスを受け取れず、
                             キーボード利用者が「なぜ押せないのか」に辿り着けない（4.3.9追記表・7.6.4-3
                             で P-31 が同じ判断をしている）。押下は `pay()` 側でも弾く。 --}}
                        <button
                            type="button"
                            x-on:click="pay()"
                            x-bind:aria-disabled="processing || method !== 'card'"
                            {{-- 既定の配色は静的なクラスに置き、押せない状態のみ `!` 付きで上書きする
                                 （Alpine の初期化前に背景色が無い状態を作らないため）。 --}}
                            x-bind:class="processing || method !== 'card' ? '!bg-stone-400 cursor-not-allowed' : 'hover:bg-red-900'"
                            class="bg-red-800 px-6 py-3 font-bold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                        >
                            {{ __('front.reservation.payment.submit') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
