{{--
    バナー画像が実在しない場合の代替表示（`Banner::hasImage()` が false のとき）。
    `BannerSeeder` は素材未用意のためダミーのパスを投入しており（9.3追記表）、
    実際の画像に差し替えるまで、掲載位置ごとの推奨サイズ（4.7.2）でプレースホルダーを
    描く。アップロードでSVGを許可しないこと（17.6-2）とは別の話であり、これは
    開発側が用意する静的なマークアップである。
--}}
@props(['position', 'label' => null])

@php
    /** @var \App\Enums\BannerPosition $position */
    $width = $position->recommendedWidth();
    $height = $position->recommendedHeight();
    // 既定の文言は管理画面（A-13）のもの。顧客側（工程7-d）から使う場合は
    // `lang/ja/front.php` の文言を `label` で渡すこと（20.1）。
    $caption = $label ?? __('admin.banner.notices.placeholder');
@endphp

<svg
    {{ $attributes->merge(['class' => 'block']) }}
    width="100%"
    viewBox="0 0 {{ $width }} {{ $height }}"
    xmlns="http://www.w3.org/2000/svg"
    role="img"
    aria-label="{{ $caption }}"
>
    <rect width="{{ $width }}" height="{{ $height }}" fill="#e5e7eb" />
    <rect x="0.5" y="0.5" width="{{ $width - 1 }}" height="{{ $height - 1 }}" fill="none" stroke="#9ca3af" stroke-width="1" />
    <line x1="0" y1="0" x2="{{ $width }}" y2="{{ $height }}" stroke="#9ca3af" stroke-width="1" />
    <line x1="{{ $width }}" y1="0" x2="0" y2="{{ $height }}" stroke="#9ca3af" stroke-width="1" />
    <text x="{{ $width / 2 }}" y="{{ $height / 2 - 6 }}" text-anchor="middle" font-size="{{ max(12, (int) ($height / 8)) }}" fill="#6b7280">{{ $width }}×{{ $height }}</text>
    <text x="{{ $width / 2 }}" y="{{ $height / 2 + 14 }}" text-anchor="middle" font-size="{{ max(10, (int) ($height / 10)) }}" fill="#6b7280">{{ $caption }}</text>
</svg>
