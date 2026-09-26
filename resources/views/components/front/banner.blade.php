{{--
    バナー1枚の部品（P-21 メインバナー・カルーセル・小バナー・各種バナーリンク、7.3-2・4・5・9）。
    掲載位置ごとに同じ部品を使う。

    props:
    - banner: \App\Models\Banner
    - eager: bool（既定 false。ファーストビューのメインバナーのみ true を渡す。13.5-6）
--}}
@props(['banner', 'eager' => false])
@php
    /** @var \App\Models\Banner $banner */
    $imageUrl = $banner->imageUrl();
    $href = $banner->safeLinkUrl();
    $width = $banner->position->recommendedWidth();
    $height = $banner->position->recommendedHeight();
    $loading = $eager ? 'eager' : 'lazy';
@endphp
@if ($href !== null)
    <a href="{{ $href }}" rel="noopener noreferrer" class="block">
        @if ($imageUrl !== null)
            <img src="{{ $imageUrl }}" alt="{{ $banner->alt }}" width="{{ $width }}" height="{{ $height }}" loading="{{ $loading }}" class="block h-auto w-full">
        @else
            <x-banner-placeholder :position="$banner->position" :label="$banner->alt" class="block w-full" />
        @endif
    </a>
@elseif ($imageUrl !== null)
    <img src="{{ $imageUrl }}" alt="{{ $banner->alt }}" width="{{ $width }}" height="{{ $height }}" loading="{{ $loading }}" class="block h-auto w-full">
@else
    <x-banner-placeholder :position="$banner->position" :label="$banner->alt" class="block w-full" />
@endif
