<?php

it('全館の名称と館トップへのリンクを表示する（P-01）', function () {
    createCinema('gion', '祇園ムビ');
    createCinema('kyoto', 'ムビ京都');

    /*
     * ヘッダーの劇場切替セレクトボックスにも全館名が候補として出るため、
     * ページ本体の一覧（data-testid="cinema-name"）を明示的に検証する。
     */
    $this->get(route('front.home'))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">祇園ムビ<', false)
        ->assertSee('data-testid="cinema-name">ムビ京都<', false)
        ->assertSee('href="'.route('front.cinema.show', ['slug' => 'gion']).'"', false)
        ->assertSee('href="'.route('front.cinema.show', ['slug' => 'kyoto']).'"', false);
});
