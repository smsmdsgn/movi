{{--
    顧客向け共通レイアウト（7.2）。

    メタ情報（19.2 / 19.3）は各画面が props で渡す。
    - title: 19.2 の形式で各画面が組み立てる
    - description: 120文字程度の要約。館ごとのページでは館名と所在地を含める
    - canonical: 正規URL。省略時は現在のURL
    - ogImage: OGP画像。作品のポスター等。サイト共通の画像は未用意（12章 残課題）
    - ogType: `website`（既定）または `article` 等
    - robots: `noindex` 等のクロール制御。予約フロー（P-31〜P-37）は上映回IDに依存し、
      時間の経過とともに存在しなくなるため除外する（19.3-6。`robots.txt` は未実装）
    - jsonLd: JSON-LD の配列（`MovieTheater` / `Movie` / `BreadcrumbList`、19.3-4・9）。
      `application/ld+json` はスクリプトとして実行されないため CSP（17.7）の script-src の対象外
--}}
@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'ogImage' => null,
    'ogType' => 'website',
    'robots' => null,
    'jsonLd' => [],
])
@php
    $pageTitle = $title ?? config('app.name', 'MOVI');
    $pageDescription = $description ?? __('front.meta.default_description');
    $canonicalUrl = $canonical ?? url()->current();
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @if ($robots)
        <meta name="robots" content="{{ $robots }}">
    @endif

    <meta property="og:site_name" content="{{ __('front.meta.site_name') }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">

    @foreach ($jsonLd as $graph)
        <script type="application/ld+json">@json($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)</script>
    @endforeach

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
