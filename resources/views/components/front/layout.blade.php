{{--
    顧客向け共通レイアウト（7.2）。

    メタ情報（19.2 / 19.3）は各画面が props で渡す。
    - title: 19.2 の形式で各画面が組み立てる
    - description: 120文字程度の要約。120文字を超える分はレイアウトが文字数で切り詰める
      （19.2。各画面は切り詰めずに渡す）。館ごとのページでは館名と所在地を含める
    - canonical: 正規URL。省略時は現在のURL
    - ogImage: OGP画像。作品のポスター等。サイト共通の画像は未用意（12章 残課題）
    - ogType: `website`（既定）または `article` 等
    - robots: `noindex` 等のクロール制御。予約フロー（P-31〜P-37）は上映回IDに依存し、
      時間の経過とともに存在しなくなるため除外する（19.3-6。`robots.txt` は未実装）
    - jsonLd: JSON-LD の配列（`MovieTheater` / `Movie` / `BreadcrumbList`、19.3-4・9）。
      `application/ld+json` はスクリプトとして実行されないため CSP（17.7）の script-src の対象外

    stack('tracking'): 計測タグの差し込み位置（4.9.3 / 4.9.7）。Cookie同意ダイアログで
    「同意する」を選択した利用者にのみ出力する。現時点で積むタグは無い
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
    // meta description の上限（19.2）。Str::limit() は表示幅（mb_strwidth）で数えるため
    // 使わず、文字数（mb_strlen と同じ数え方）の Str::substr() で切り詰める（旧12章 残課題46）。
    // 切り詰める前に改行・連続する空白を1つにまとめ（あらすじ等は改行を含む）、切り詰めた後も
    // 末尾の空白を落とす（Str::limit() の rtrim と同じ）。
    $descriptionLimit = 120;
    $pageTitle = $title ?? config('app.name', 'MOVI');
    $pageDescription = rtrim(\Illuminate\Support\Str::substr(
        \Illuminate\Support\Str::squish($description ?? __('front.meta.default_description')),
        0,
        $descriptionLimit,
    ));
    $canonicalUrl = $canonical ?? url()->current();
    $cookieConsent = \App\Enums\CookieConsent::fromRequest(request());
@endphp
<!DOCTYPE html>
{{-- Cookie同意ダイアログを出すページでは、キーボードでフォーカスした要素が画面下部の
     ダイアログの裏に隠れないよう、スクロールの下端に余白を取る（4.9.7「表示位置」）。
     値はダイアログの高さ（375px幅で約200px、md 以上で約100px）に合わせる。 --}}
<html lang="ja" @class(['scroll-pb-52 md:scroll-pb-32' => $cookieConsent === null])>
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

    {{-- 画面固有の外部スクリプト（決済 P-36 の Stripe.js 等）。`@livewireScripts` より前に
         置き、`defer` の実行順（文書順）で Alpine の初期化より先に読み込ませる。
         スタックは本レイアウトの描画より前にスロット側で積まれるため、head で受けられる。 --}}
    @stack('head')

    {{-- 計測タグの差し込み位置（4.9.3「拒否時にタグを出力しない分岐」/ 4.9.7「計測タグの分岐」）。
         Cookie同意ダイアログで「同意する」を選択した利用者にのみ出力する。未選択・拒否・
         未知の値では出力しない（オプトイン）。選択した画面自体ではまだ出ず、次のページ
         読み込みから出る（Cookie がその応答で初めて Set-Cookie されるため）。
         現時点で積むタグは無い。積む際は CSP（17.7）に従うこと（インラインの初期化
         スクリプトは使えず、配信元を script-src / connect-src / img-src に加える）。 --}}
    @if ($cookieConsent?->allowsTracking())
        @stack('tracking')
    @endif
</head>
<body class="flex min-h-screen flex-col bg-white text-stone-900">
    <x-front.header />

    <main class="flex-1">
        {{ $slot }}
    </main>

    <x-front.footer />

    {{-- Cookie同意ダイアログ（4.9.3 / 4.9.7）。選択済みの利用者にはコンポーネントごと
         出力しない（レイアウトが Cookie を読んで分岐する）。<body> の直下に置くこと
         （ルート要素の sticky が効くため。内側に置くと親要素の高さの分しか動けない）。 --}}
    @if ($cookieConsent === null)
        <livewire:front.cookie-consent.dialog />
    @endif

    @livewireScripts
</body>
</html>
