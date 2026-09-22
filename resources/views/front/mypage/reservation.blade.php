{{--
    マイページの予約詳細（P-06、7.14）。明細の表示とキャンセルの操作は Livewire
    コンポーネントが担い、本ビューは共通レイアウト・メタ情報・パンくずを組み立てる
    （P-07 の `front/lookup/index.blade.php` と同じ分担）。

    $reservationId: 表示する予約のID（コントローラが所有者を確認済み）

    **館非依存ページのため、パンくずに館を挟まない**（19.3-9）。会員専用であり
    インデックス対象外（19.3-6）のため BreadcrumbList の構造化データは付けない。

    館はビューで扱わない。ヘッダー（`x-front.header`）が `CurrentCinemaService` から
    自前で解決する。
--}}
<x-front.layout
    :title="__('front.mypage.detail.title')"
    :description="__('front.mypage.detail.description')"
    robots="noindex, nofollow"
>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <x-front.breadcrumb :items="[
            ['label' => __('front.breadcrumb.home'), 'url' => route('front.home')],
            ['label' => __('front.mypage.heading'), 'url' => route('front.mypage.index')],
            ['label' => __('front.mypage.detail.heading'), 'url' => null],
        ]" />

        <h1 class="mt-4 text-2xl font-bold">{{ __('front.mypage.detail.heading') }}</h1>

        <div class="mt-6">
            <livewire:front.my-page.reservation-detail :reservation-id="$reservationId" />
        </div>
    </div>
</x-front.layout>
