@php
    $currentAdmin = auth('admin')->user()?->loadMissing('cinema');

    $canViewScreen = fn (string $routeName): bool => \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('view-admin-screen', $routeName);

    $navItems = [
        ['admin.cinema.index', 'admin.nav.cinemas'],
        ['admin.theater.index', 'admin.nav.theaters'],
        ['admin.movie.index', 'admin.nav.movies'],
        ['admin.format.index', 'admin.nav.formats'],
        ['admin.ticket-type.index', 'admin.nav.ticket_types'],
        ['admin.booking.index', 'admin.nav.bookings'],
        ['admin.screening.index', 'admin.nav.screenings'],
        ['admin.reservation.index', 'admin.nav.reservations'],
        ['admin.reservation.search', 'admin.nav.reservation_search'],
        ['admin.post.index', 'admin.nav.posts'],
        ['admin.banner.index', 'admin.nav.banners'],
        ['admin.account.index', 'admin.nav.admins'],
        ['admin.gate.index', 'admin.nav.gate'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <a href="{{ route($currentAdmin->landingRouteName()) }}" wire:navigate class="px-2 py-1 text-sm font-semibold">{{ __('admin.layout.brand') }}</a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @if ($canViewScreen('admin.dashboard'))
                    <flux:sidebar.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>{{ __('admin.nav.dashboard') }}</flux:sidebar.item>
                @endif

                @foreach ($navItems as [$routeName, $labelKey])
                    @if ($canViewScreen($routeName))
                        <flux:sidebar.item :href="route($routeName)" :current="request()->routeIs($routeName)" wire:navigate>{{ __($labelKey) }}</flux:sidebar.item>
                    @endif
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                @if ($canViewScreen('admin.password.edit'))
                    <flux:sidebar.item :href="route('admin.password.edit')" :current="request()->routeIs('admin.password.edit')" wire:navigate>{{ __('admin.nav.password') }}</flux:sidebar.item>
                @endif
            </flux:sidebar.nav>
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:spacer />
        </flux:header>

        <flux:main>
            <div class="mb-6 flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 pb-4 dark:border-zinc-700">
                <div class="text-sm">
                    <span class="font-medium">{{ $currentAdmin->name }}</span>
                    <span class="text-zinc-500">（{{ __('admin.role.'.$currentAdmin->role->value) }}@if ($currentAdmin->cinema) ・{{ $currentAdmin->cinema->name }}@endif）</span>
                </div>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <flux:button type="submit" size="sm">{{ __('admin.layout.logout') }}</flux:button>
                </form>
            </div>

            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
