<x-front.layout :title="__('front.chain_top.title')">
    <div class="px-4 py-8">
        <h1 class="text-xl font-bold">{{ __('front.chain_top.heading') }}</h1>

        <ul class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach ($cinemas as $cinema)
                <li class="border border-stone-300 p-4">
                    <p class="font-bold" data-testid="cinema-name">{{ $cinema->name }}</p>
                    <p class="mt-1 text-sm text-stone-600">{{ $cinema->concept }}</p>
                    <a
                        href="{{ route('front.cinema.show', ['slug' => $cinema->slug]) }}"
                        class="mt-3 inline-block text-sm font-bold text-brand underline"
                    >
                        {{ __('front.chain_top.view_cinema') }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-front.layout>
