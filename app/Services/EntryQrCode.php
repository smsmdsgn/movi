<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * 入場用QRコードのPNGを生成する（4.6.2）。
 *
 * **QRの内容は入場コードの文字列のみとし、URLを含めない**（4.6.2-2）。
 * `PngWriter` は GD で描画する（4.6.2-3。Imagick に依存しない）。
 *
 * **画面へはデータURIで埋め込む。** 画像を返すルートを設けると、予約照会（P-07。
 * 照合済みのセッションで閲覧権を持つ）・予約完了（P-38）・マイページ（P-06）・
 * 予約検索（A-11）のそれぞれの閲覧権をルート側にも複製することになる。
 * データURIなら、明細を表示できる者だけがQRを受け取る（CSP の `img-src` は
 * `data:` を許可している。17.7）。
 */
class EntryQrCode
{
    /** 生成する画像の一辺（px。余白を含む）。スマートフォンの画面をゲートのカメラにかざして読める大きさ。 */
    public const int SIZE = 240;

    /** 余白（px。片側）。`Builder` の `size` は余白を含まないため、`SIZE` から差し引いて渡す。 */
    private const int MARGIN = 12;

    public function dataUri(string $entryCode): string
    {
        return (new Builder(
            writer: new PngWriter,
            data: $entryCode,
            // 画面のひび割れ・反射を想定し、既定（Low）より一段上げる。
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            // 出力の一辺は `size + 2 * margin` になる。`<img>` の寸法（SIZE）と一致させ、
            // 縮小表示でモジュールの輪郭がぼやけないようにする。
            size: self::SIZE - 2 * self::MARGIN,
            margin: self::MARGIN,
        ))->build()->getDataUri();
    }
}
