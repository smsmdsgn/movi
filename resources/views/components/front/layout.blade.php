@props(['title' => null])
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name', 'MOVI') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @fonts
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="flex min-h-screen flex-col bg-white text-stone-900">
    <x-front.header />

    <main class="flex-1">
        {{ $slot }}
    </main>

    <x-front.footer />

    @livewireScripts
</body>
</html>
