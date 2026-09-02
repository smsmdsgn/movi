<?php

test('returns a successful response', function () {
    /* 共通レイアウトのヘッダーが既定の館（祇園ムビ）を解決するため、事前に作成しておく。 */
    createCinema('gion', '祇園ムビ');

    $response = $this->get(route('front.home'));

    $response->assertOk();
});
