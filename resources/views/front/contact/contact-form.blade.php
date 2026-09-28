{{--
    お問い合わせフォーム（P-14、4.9.2）の Livewire ビュー。

    render() から渡る変数:
    - $categories: `App\Enums\ContactCategory::cases()`（4.9.2 の選択肢の並び）
    - $siteKey: Turnstile のサイトキー（`TURNSTILE_SITE_KEY`）。サイトキー・シークレットキーの
      いずれかが未設定の場合は null（`TurnstileService::isConfigured()`。4.9.8「キー未設定時」）

    ライブリージョンはルート直下に常設し、中身だけを差し替える
    （`front.reservation.customer-form` と同じ扱い）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
<div>
    {{-- 「ご入力の内容に…件の誤り」は入力項目の誤りだけを数える。人間であることの確認
         （turnstileToken）の失敗は、通信の失敗やキー未設定のように利用者の入力に原因が
         無い場合を含むため数えず、それだけが残る場合はその文言を要約に出す（20.3-2）。 --}}
    @php
        $inputErrorKeys = array_values(array_diff($errors->keys(), ['turnstileToken']));
    @endphp
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($inputErrorKeys !== [])
            <p class="mb-4 border border-red-700 bg-red-50 p-3 text-sm text-red-900">
                {{ __('front.contact.errors.summary', ['count' => count($inputErrorKeys)]) }}
                <a href="#contact-{{ $inputErrorKeys[0] }}" class="underline">
                    {{ __('front.contact.errors.jump_to_first') }}
                </a>
            </p>
        @elseif ($errors->has('turnstileToken'))
            <p class="mb-4 border border-red-700 bg-red-50 p-3 text-sm text-red-900">
                {{ $errors->first('turnstileToken') }}
                <a href="#contact-turnstileToken" class="underline">
                    {{ __('front.contact.errors.jump_to_turnstile') }}
                </a>
            </p>
        @endif
    </div>

    {{-- `wire:submit` で送る。Enter キーでの送信を拾え、ボタン以外の導線を足しても壊れない。 --}}
    <form wire:submit="submit" class="mt-4 space-y-5">
        @foreach (['name', 'email'] as $property)
            @php
                $inputId = 'contact-'.$property;
                $hasError = $errors->has($property);
            @endphp

            <div>
                <label for="{{ $inputId }}" class="block font-bold">
                    {{ __('front.contact.fields.'.$property) }}
                    {{-- 必須は色と記号の双方で示す（13.5-5）。読み上げは aria-required が担う。 --}}
                    <span class="ml-1 align-middle text-xs text-red-800">※必須</span>
                </label>

                <input
                    id="{{ $inputId }}"
                    type="{{ $property === 'email' ? 'email' : 'text' }}"
                    wire:model="{{ $property }}"
                    autocomplete="{{ $property === 'email' ? 'email' : 'name' }}"
                    aria-required="true"
                    @if ($hasError) aria-describedby="{{ $inputId }}-error" @endif
                    @if ($hasError) aria-invalid="true" @endif
                    class="mt-2 w-full border px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 {{ $hasError ? 'border-red-700 bg-red-50' : 'border-stone-400' }}"
                >

                @error($property)
                    <p id="{{ $inputId }}-error" class="mt-1 text-sm text-red-900">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        @php
            $categoryHasError = $errors->has('category');
        @endphp

        <div>
            <label for="contact-category" class="block font-bold">
                {{ __('front.contact.fields.category') }}
                <span class="ml-1 align-middle text-xs text-red-800">※必須</span>
            </label>

            <select
                id="contact-category"
                wire:model="category"
                aria-required="true"
                @if ($categoryHasError) aria-describedby="contact-category-error" @endif
                @if ($categoryHasError) aria-invalid="true" @endif
                class="mt-2 w-full border px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 {{ $categoryHasError ? 'border-red-700 bg-red-50' : 'border-stone-400' }}"
            >
                <option value="">{{ __('front.contact.category_placeholder') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->value }}">{{ __($category->labelKey()) }}</option>
                @endforeach
            </select>

            @error('category')
                <p id="contact-category-error" class="mt-1 text-sm text-red-900">{{ $message }}</p>
            @enderror
        </div>

        @php
            $bodyHasError = $errors->has('body');
        @endphp

        <div>
            <label for="contact-body" class="block font-bold">
                {{ __('front.contact.fields.body') }}
                <span class="ml-1 align-middle text-xs text-red-800">※必須</span>
            </label>

            <textarea
                id="contact-body"
                wire:model="body"
                rows="8"
                aria-required="true"
                @if ($bodyHasError) aria-describedby="contact-body-error" @endif
                @if ($bodyHasError) aria-invalid="true" @endif
                class="mt-2 w-full border px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 {{ $bodyHasError ? 'border-red-700 bg-red-50' : 'border-stone-400' }}"
            ></textarea>

            @error('body')
                <p id="contact-body-error" class="mt-1 text-sm text-red-900">{{ $message }}</p>
            @enderror
        </div>

        {{-- 人間であることの確認（Cloudflare Turnstile、4.9.2 / 4.9.8）。「ボット対策」
             「トークン」の語は使わない（20.3-4）。`id="contact-turnstileToken"` は
             冒頭のエラー要約のリンク（`#contact-{最初のエラーのキー}`）の飛び先になる。 --}}
        {{-- 要約のリンクで移動したときにフォーカスを受けられるよう tabindex="-1" を付ける
             （他の項目の飛び先は入力欄そのものだが、ここの入力は Cloudflare の iframe のため）。 --}}
        <div id="contact-turnstileToken" tabindex="-1">
            <p id="contact-turnstileToken-label" class="block font-bold">
                {{ __('front.contact.fields.turnstileToken') }}
                <span class="ml-1 align-middle text-xs text-red-800">※必須</span>
            </p>

            @if ($siteKey === null)
                {{-- キー未設定（4.9.8「キー未設定時」）。利用者の操作では解消しないため
                     ウィジェットを出さず、送信できない旨だけを案内する。 --}}
                <p class="mt-2 border border-stone-300 bg-stone-100 p-3 text-sm">
                    {{ __('front.contact.turnstile.unavailable') }}
                </p>
            @else
                {{-- ウィジェットの描画は Alpine の x-init から行う（4.9.8「ウィジェットの
                     描画」）。差し込み先（$refs.widget）に wire:ignore を付け、Livewire の
                     再描画で iframe ごと差し替えられないようにする（P-36 の Stripe Elements
                     と同じ扱い）。トークンは callback で $wire.$set(..., false) へ渡し、
                     通信を起こさず次の送信に載せる。期限切れ・エラー時は空に戻す。 --}}
                <div
                    x-data="{
                        widgetId: null,
                        unavailable: false,
                        renderWidget() {
                            if (typeof turnstile === 'undefined') {
                                this.unavailable = true;

                                return;
                            }

                            this.widgetId = turnstile.render($refs.widget, {
                                sitekey: @js($siteKey),
                                language: 'ja',
                                callback: (token) => $wire.$set('turnstileToken', token, false),
                                'expired-callback': () => $wire.$set('turnstileToken', '', false),
                                'error-callback': () => $wire.$set('turnstileToken', '', false),
                            });
                        },
                        resetWidget() {
                            // 先に空へ戻してからやり直す。逆順だと、やり直しで発行された
                            // 新しいトークンを空で上書きしうる。
                            $wire.$set('turnstileToken', '', false);

                            if (this.widgetId !== null) {
                                turnstile.reset(this.widgetId);
                            }
                        },
                    }"
                    x-init="renderWidget()"
                    x-on:turnstile-reset.window="resetWidget()"
                >
                    <div
                        wire:ignore
                        x-ref="widget"
                        role="group"
                        class="mt-2"
                        aria-labelledby="contact-turnstileToken-label"
                    ></div>

                    {{-- api.js の読み込み自体に失敗した場合（4.9.8「キー未設定時」と同趣旨。
                         サーバー側の設定漏れではなく、利用者側の通信状況による）。 --}}
                    <p
                        x-cloak
                        x-show="unavailable"
                        role="alert"
                        aria-live="assertive"
                        class="mt-2 border border-stone-300 bg-stone-100 p-3 text-sm"
                    >
                        {{ __('front.contact.turnstile.unavailable') }}
                    </p>
                </div>
            @endif

            @error('turnstileToken')
                <p id="contact-turnstileToken-error" class="mt-1 text-sm text-red-900">{{ $message }}</p>
            @enderror
        </div>

        <div class="pt-1">
            <button
                type="submit"
                class="bg-red-800 px-6 py-3 font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
            >
                {{ __('front.contact.submit') }}
            </button>
        </div>
    </form>
</div>
