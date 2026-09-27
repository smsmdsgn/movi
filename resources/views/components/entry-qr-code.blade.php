{{--
    入場用QRコードの画像（4.6.2）。QRの内容は入場コードの文字列のみで、URLを含めない
    （4.6.2-2）。画像はデータURIとして埋め込む（理由は `App\Services\EntryQrCode` の
    docblockを参照。明細を表示できる者だけが入場コードを受け取れるようにするため）。

    予約検索（A-11）と顧客側（予約完了 P-38 / マイページ P-06 / 予約照会 P-07）の
    双方で使う匿名コンポーネント。

    $code: string（`t_reservations.entry_code`）
    $alt: string（代替テキスト）
--}}
@props(['code', 'alt'])
<img
    src="{{ app(\App\Services\EntryQrCode::class)->dataUri($code) }}"
    alt="{{ $alt }}"
    width="{{ \App\Services\EntryQrCode::SIZE }}"
    height="{{ \App\Services\EntryQrCode::SIZE }}"
    {{ $attributes }}
>
