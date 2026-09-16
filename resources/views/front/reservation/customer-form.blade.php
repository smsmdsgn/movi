{{--
    お客様情報の入力（P-34、7.9 / 4.3.6）の Livewire ビュー。

    render() から渡る変数:
    - $onSale: 販売期間内の上映回が存在するか（4.3.1 / 4.3.10）
    - $canProceed: 先へ進める前提（販売期間・座席の保持・利用規約への同意）が揃っているか
    - $noticeKey: 表示する案内の文言キー（7.17）。無い場合は null
    - $recoveryUrl / $recoveryLabelKey: 前提を満たしていない場合の復帰先（4.3.12）
    - $identifyUrl: ご購入方法の選択（P-33）のURL

    ライブリージョンはルート直下に常設し、中身だけを差し替える（P-32・P-33 と同じ扱い）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
<div>
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($noticeKey !== null)
            {{-- 販売期間外・削除済みの回は利用者の操作の失敗ではないため中立の配色にする（4.3.10）。 --}}
            <p class="mb-4 border p-3 text-sm {{ $onSale ? 'border-red-700 bg-red-50 text-red-900' : 'border-stone-300 bg-stone-100' }}">
                {{ __($noticeKey) }}
            </p>
        @elseif ($errors->isNotEmpty())
            {{-- 入力の誤りは項目ごとに出す（下）が、それだけでは送信が失敗したこと自体が
                 読み上げられず「押したが何も起きない」ように見える。件数の要約を
                 ライブリージョンへ流し、誤った最初の項目への移動手段を添える。 --}}
            <p class="mb-4 border border-red-700 bg-red-50 p-3 text-sm text-red-900">
                {{ __('front.reservation.customer.errors.summary', ['count' => $errors->count()]) }}
                <a href="#customer-{{ $errors->keys()[0] }}" class="underline">
                    {{ __('front.reservation.customer.errors.jump_to_first') }}
                </a>
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
        <p class="text-sm text-stone-600">{{ __('front.reservation.customer.lead') }}</p>

        {{-- `wire:submit` で送る。Enter キーでの送信を拾え、ボタン以外の導線を足しても壊れない。 --}}
        <form wire:submit="submit" class="mt-4 space-y-5">
            @foreach ([
                ['property' => 'name', 'type' => 'text', 'autocomplete' => 'name'],
                ['property' => 'nameKana', 'type' => 'text', 'autocomplete' => 'off'],
                ['property' => 'phone', 'type' => 'tel', 'autocomplete' => 'tel'],
                ['property' => 'email', 'type' => 'email', 'autocomplete' => 'email'],
                ['property' => 'emailConfirmation', 'type' => 'email', 'autocomplete' => 'off'],
            ] as $field)
                @php
                    $property = $field['property'];
                    $inputId = 'customer-'.$property;
                    $hintId = $inputId.'-hint';
                    $hasError = $errors->has($property);
                @endphp

                <div>
                    <label for="{{ $inputId }}" class="block font-bold">
                        {{ __('front.reservation.customer.fields.'.$property) }}
                        {{-- 必須は色と記号の双方で示す（13.5-5）。読み上げは aria-required が担う。 --}}
                        <span class="ml-1 align-middle text-xs text-red-800">※必須</span>
                    </label>

                    <p id="{{ $hintId }}" class="mt-1 text-sm text-stone-600">
                        {{ __('front.reservation.customer.hints.'.$property) }}
                    </p>

                    <input
                        id="{{ $inputId }}"
                        type="{{ $field['type'] }}"
                        wire:model="{{ $property }}"
                        autocomplete="{{ $field['autocomplete'] }}"
                        aria-required="true"
                        aria-describedby="{{ $hintId }}{{ $hasError ? ' '.$inputId.'-error' : '' }}"
                        @if ($hasError) aria-invalid="true" @endif
                        class="mt-2 w-full border px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 {{ $hasError ? 'border-red-700 bg-red-50' : 'border-stone-400' }}"
                    >

                    @error($property)
                        {{-- 項目ごとの誤りは該当の入力欄に紐づける。画面上部へまとめると
                             どの欄を直せばよいかが分からない（7.17 のトーン）。 --}}
                        <p id="{{ $inputId }}-error" class="mt-1 text-sm text-red-900">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                <a href="{{ $identifyUrl }}" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                    {{ __('front.reservation.customer.back_to_identify') }}
                </a>

                <button
                    type="submit"
                    class="bg-red-800 px-6 py-3 font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                >
                    {{ __('front.reservation.customer.proceed') }}
                </button>
            </div>
        </form>
    @endif
</div>
