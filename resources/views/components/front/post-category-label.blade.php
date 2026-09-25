{{--
    お知らせのカテゴリー名のラベル（P-24〜P-26）。「重要なお知らせ」は強調色で示す。
    色だけに頼らない表現（13.5-5）はカテゴリー名そのものが担う。

    props:
    - category: PostCategory
    - href: string|null（指定時はリンクにする）
--}}
@props(['category', 'href' => null])
@php
    $tone = $category->isImportant()
        ? 'border-red-800 bg-red-50 text-red-900'
        : 'border-stone-400 text-stone-600';
@endphp
@if ($href !== null)
    <a href="{{ $href }}" class="inline-block w-fit shrink-0 border px-2 py-0.5 text-xs font-bold underline {{ $tone }}">{{ $category->name }}</a>
@else
    <span class="inline-block w-fit shrink-0 border px-2 py-0.5 text-xs font-bold {{ $tone }}">{{ $category->name }}</span>
@endif
