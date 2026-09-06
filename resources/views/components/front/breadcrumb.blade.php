{{--
    館配下ページのパンくずリスト（19.3-9）。

    props:
    - items: 配列。各要素は ['label' => string, 'url' => string|null]。
      最後の要素は現在ページであり url は null とする。
      2番目の要素（$index === 1）は必ず館名であり、`data-testid="cinema-name"` を付与する
      （既存テスト `tests/Feature/Front/ResolveCinemaTest.php` が判定に使う）。
--}}
@props(['items'])
<nav aria-label="{{ __('front.breadcrumb.label') }}">
    <ol class="flex flex-wrap gap-1 text-sm text-stone-600">
        @foreach ($items as $index => $item)
            <li class="flex items-center gap-1">
                @if (! $loop->first)
                    <span aria-hidden="true">/</span>
                @endif
                @if ($item['url'] !== null)
                    <a href="{{ $item['url'] }}" class="underline decoration-stone-400 hover:text-brand"@if ($index === 1) data-testid="cinema-name"@endif>{{ $item['label'] }}</a>
                @else
                    <span aria-current="page"@if ($index === 1) data-testid="cinema-name"@endif>{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
