{{--
    マイページの予約詳細（P-06、7.14）の Livewire ビュー。

    render() から渡る変数:
    - $reservation: Reservation（ログイン中の会員が所有するもの。seats / screening を読み込み済み）
    - $confirmingCancel: この予約に対する確認を出しているか
    - $cancelledNotice: キャンセルが成立した場合の案内の文言キー（無ければ null）
    - $cancelError: キャンセルを承れなかった理由の文言キー（無ければ null）
    - $refundPending: キャンセルは成立したが返金が未了か（見出しと配色を分ける）
    - $cancelState: キャンセルの導線（4.4 / 7.19-8）。{available, noticeKey}、出さない場合は null

    明細（7.14「入場用QRコード、予約内容、領収書」）とキャンセルの節は
    `x-front.reservation.detail` / `x-front.reservation.cancel-section` が描く
    （P-07 と共有する）。

    **先頭の PHP ブロックは docblock だけに留め、Blade コメントの中にディレクティブの
    綴りを書かないこと**（4.3.17。コメントの除去より生PHPブロックの抽出が先に走る）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
@php
    /** @var \App\Models\Reservation $reservation */
@endphp
<div>
    {{-- ライブリージョンはルート直下に常設し、中身だけを差し替える（P-07 と同じ扱い）。 --}}
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($cancelError !== null)
            {{-- キャンセルを承れなかった理由（4.4）。課金にも座席にも触れていない。 --}}
            <p class="mb-4 border border-red-700 bg-red-50 p-3 text-sm text-red-900">
                {{ __($cancelError) }}
            </p>
        @endif
    </div>

    <x-front.reservation.detail :reservation="$reservation" id-prefix="mypage-reservation" />

    <x-front.reservation.cancel-section
        :cancel-state="$cancelState"
        :confirming-cancel="$confirmingCancel"
        :cancelled-notice="$cancelledNotice"
        :refund-pending="$refundPending"
        id-prefix="mypage-reservation"
    />

    <div class="mt-6">
        <a href="{{ route('front.mypage.index') }}" class="inline-block border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
            {{ __('front.mypage.detail.back') }}
        </a>
    </div>
</div>
