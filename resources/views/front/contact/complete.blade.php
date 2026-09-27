{{--
    お問い合わせ送信完了（P-15、4.9.2-3）。ダミー実装であり、実際には送信されない旨を
    明示する。到達の根拠（セッションのフラグ）は `ContactCompleteController` が確認済み。

    館はビューで扱わない。ヘッダー（`x-front.header`）が `CurrentCinemaService` から
    自前で解決する。

    予約完了に準じ、操作の結果としてのみ到達する画面のためクロール対象外とする
    （19.3-6 / 4.9.5「サイトマップの範囲」）。
--}}
<x-front.page
    :title="__('front.contact.complete.title')"
    :heading="__('front.contact.complete.heading')"
    :description="__('front.contact.complete.description')"
    robots="noindex, nofollow"
>
    <p class="border border-stone-300 bg-stone-50 p-3 text-sm">
        {{ __('front.contact.dummy_notice') }}
    </p>

    <p>
        <a href="{{ route('front.home') }}" class="inline-block border border-stone-400 px-4 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
            {{ __('front.contact.complete.back_to_home') }}
        </a>
    </p>
</x-front.page>
