<?php

use App\Models\Cinema;

/**
 * P-28（アクセス）の地図の iframe に出してよい埋め込みURLの判定（17.7 の `frame-src`、4.9.4）。
 */
it('地図の埋め込みURLは https://www.google.com/ で始まるものに限る', function (string $url, ?string $expected) {
    $cinema = new Cinema(['map_embed_url' => $url]);

    expect($cinema->safeMapEmbedUrl())->toBe($expected);
})->with([
    'Google マップ' => ['https://www.google.com/maps?q=test&output=embed', 'https://www.google.com/maps?q=test&output=embed'],
    'http' => ['http://www.google.com/maps?q=test', null],
    '別ホスト' => ['https://www.google.com.evil.example/maps', null],
    'サブドメイン違い' => ['https://maps.google.com/maps?q=test', null],
    'javascript' => ['javascript:alert(1)', null],
    'data' => ['data:text/html,<script>alert(1)</script>', null],
    '空白を含む' => ['https://www.google.com/maps onload=alert(1)', null],
    '空文字' => ['', null],
]);
