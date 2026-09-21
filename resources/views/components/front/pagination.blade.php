{{--
    顧客向けのページネーション（7.14 構成要素3 が最初の利用者）。

    **フレームワーク同梱の `pagination::tailwind` を使わない。** 既定ビューは
    `sm:` ブレークポイント（13.5-2 が禁止）、`gray` / `blue` 系と `dark:` の配色
    （18.2 と不一致）、英語の文言（20.1-3 違反。`lang/ja` を置いても
    `__('Showing')` のようにキー自体が英文の箇所が残る）をそのまま出力する。

    **番号は現在地の前後 `WINDOW` ページだけを出し、間を省略する。** 全ページぶんを
    並べると、履歴が数十ページある会員でモバイル（5.1-1）の1行が何度も折り返す。
    既定ビューが持っていた省略を外さないための措置。

    $paginator: LengthAwarePaginator

    使い方: `<x-front.pagination :paginator="$history" />`
--}}
@props(['paginator'])
@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed> $paginator */

    /** 現在地の前後に出すページ番号の数。 */
    $window = 2;

    $last = $paginator->lastPage();
    $current = $paginator->currentPage();
    $from = max(1, $current - $window);
    $to = min($last, $current + $window);

    $linkClass = 'inline-block border border-stone-400 px-3 py-2 text-sm tabular-nums underline decoration-stone-400 hover:bg-stone-100';
    $mutedClass = 'inline-block border border-stone-300 px-3 py-2 text-sm text-stone-400';
@endphp
@if ($paginator->hasPages())
    <nav {{ $attributes->merge(['class' => 'mt-4', 'aria-label' => __('front.pagination.label')]) }}>
        <p class="text-sm text-stone-600">
            {{ __('front.pagination.summary', [
                'first' => $paginator->firstItem(),
                'last' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ]) }}
        </p>

        <ul class="mt-2 flex flex-wrap items-center gap-1">
            {{-- 前へ。先頭ページでは押せないことを `aria-disabled` でも示す（13.5-5）。 --}}
            <li>
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" class="{{ $mutedClass }}">{{ __('pagination.previous') }}</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-block border border-stone-400 px-3 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
                        {{ __('pagination.previous') }}
                    </a>
                @endif
            </li>

            {{-- 先頭ページと省略記号。窓が先頭から離れている場合のみ出す。 --}}
            @if ($from > 1)
                <li>
                    <a href="{{ $paginator->url(1) }}" aria-label="{{ __('front.pagination.goto', ['page' => 1]) }}" class="{{ $linkClass }}">1</a>
                </li>
                @if ($from > 2)
                    <li aria-hidden="true" class="px-1 text-sm text-stone-500">…</li>
                @endif
            @endif

            @foreach ($paginator->getUrlRange($from, $to) as $page => $url)
                <li>
                    @if ($page === $current)
                        {{-- 現在地は色のみで示さず `aria-current` でも伝える（13.5-5）。
                             素の `<span>` への `aria-label` は支援技術が読まないため付けない
                             （`x-front.breadcrumb` と同じ扱い）。 --}}
                        <span aria-current="page" class="inline-block border-2 border-red-800 bg-red-50 px-3 py-2 text-sm font-bold tabular-nums text-red-900">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" aria-label="{{ __('front.pagination.goto', ['page' => $page]) }}" class="{{ $linkClass }}">{{ $page }}</a>
                    @endif
                </li>
            @endforeach

            {{-- 省略記号と末尾ページ。 --}}
            @if ($to < $last)
                @if ($to < $last - 1)
                    <li aria-hidden="true" class="px-1 text-sm text-stone-500">…</li>
                @endif
                <li>
                    <a href="{{ $paginator->url($last) }}" aria-label="{{ __('front.pagination.goto', ['page' => $last]) }}" class="{{ $linkClass }}">{{ $last }}</a>
                </li>
            @endif

            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-block border border-stone-400 px-3 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
                        {{ __('pagination.next') }}
                    </a>
                @else
                    <span aria-disabled="true" class="{{ $mutedClass }}">{{ __('pagination.next') }}</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
