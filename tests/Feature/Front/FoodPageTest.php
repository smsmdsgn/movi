<?php

/*
 * フード・ドリンクメニュー（P-09、7.1.1 / 4.9.1。工程7-f）。
 * 取り扱い品目は館により異なるため、各館の施設案内（P-27）への導線を確認する。
 */
it('各館の施設案内へのリンクを表示する', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $shijo = createCinema('shijo-karasuma', '四条烏丸ムビ');

    $this->get(route('front.food.index'))
        ->assertOk()
        ->assertSee('href="'.route('front.establishment.index', ['slug' => $gion->slug]).'"', false)
        ->assertSee('href="'.route('front.establishment.index', ['slug' => $shijo->slug]).'"', false);
});
