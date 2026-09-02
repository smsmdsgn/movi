<header class="relative bg-brand text-white">
    <div class="flex items-center gap-4 px-4 py-3">
        <a href="{{ route('front.cinema.show', ['slug' => $currentCinema->slug]) }}" class="shrink-0 font-bold tracking-wide">
            {{ __('front.header.logo_alt') }}
        </a>

        <label class="sr-only" for="front-header-cinema-switch">{{ __('front.header.cinema_switch_label') }}</label>
        <select
            id="front-header-cinema-switch"
            class="min-w-0 flex-1 border border-white/40 bg-brand px-2 py-1 text-sm text-white md:flex-none"
            x-data
            x-on:change="if ($event.target.value) { window.location.href = $event.target.value }"
        >
            @foreach ($switchOptions as $option)
                <option value="{{ $option['url'] }}" @selected($option['cinema']->is($currentCinema))>
                    {{ $option['cinema']->name }}
                </option>
            @endforeach
        </select>

        <div class="hidden md:block">
            @auth
                <a href="{{ route('front.mypage.index') }}" class="text-sm">{{ __('front.header.mypage') }}</a>
            @else
                <a href="{{ route('login') }}" class="text-sm">{{ __('front.header.login') }}</a>
            @endauth
        </div>

        <details class="md:hidden">
            <summary class="cursor-pointer list-none text-sm" aria-label="{{ __('front.header.menu') }}">☰</summary>
            <div class="absolute right-4 mt-2 bg-brand px-4 py-3">
                @auth
                    <a href="{{ route('front.mypage.index') }}" class="text-sm">{{ __('front.header.mypage') }}</a>
                @else
                    <a href="{{ route('login') }}" class="text-sm">{{ __('front.header.login') }}</a>
                @endauth
            </div>
        </details>
    </div>
</header>
