{{--
    お問い合わせフォーム（P-14、4.9.2）の Livewire ビュー。

    render() から渡る変数:
    - $categories: `App\Enums\ContactCategory::cases()`（4.9.2 の選択肢の並び）

    ライブリージョンはルート直下に常設し、中身だけを差し替える
    （`front.reservation.customer-form` と同じ扱い）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
<div>
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($errors->isNotEmpty())
            <p class="mb-4 border border-red-700 bg-red-50 p-3 text-sm text-red-900">
                {{ __('front.contact.errors.summary', ['count' => $errors->count()]) }}
                <a href="#contact-{{ $errors->keys()[0] }}" class="underline">
                    {{ __('front.contact.errors.jump_to_first') }}
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
