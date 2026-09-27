{{--
    工程2（館切替とルーティング）の検証用プレースホルダ。館別ページと P-08〜P-13・
    P-16〜P-20 は実装済みのため、現在は P-14・P-15（お問い合わせ、未実装。
    `PagePlaceholderController`）でのみ使用する。
--}}
@php
    // 予約フロー（P-31〜P-38）は上映回IDに依存し、時間の経過とともに存在しなくなるURLの
    // ためクロール対象外とする（19.3-6 / 4.3.9）。骨格の段階でも P-31 と扱いを揃える。
    $robots = \Illuminate\Support\Str::startsWith($screenId, 'P-3') ? 'noindex, nofollow' : null;
@endphp
<x-front.layout :title="$screenId.' / '.$cinema->name" :robots="$robots">
    <div class="px-4 py-8">
        <p data-testid="screen-id">{{ $screenId }}</p>
        <p data-testid="cinema-slug">{{ $cinema->slug }}</p>
        <p data-testid="cinema-name">{{ $cinema->name }}</p>
    </div>
</x-front.layout>
