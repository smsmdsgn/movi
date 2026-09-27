<?php

use App\Enums\SeatDisplayClass;
use App\Models\Format;
use App\Models\SeatType;
use App\Models\TicketType;
use App\Services\PricingService;

/*
 * 料金表・割引サービス（P-08、7.1.1 / 4.9.1 / 6.5。工程7-f）。
 * 券種・上映規格・座席の追加料金はマスタから動的に生成し、割引の金額は
 * `PricingService` の定数と一致させる（pricing スキル。直書きしない）。
 */
it('券種をdisplay_order順に名称・価格・条件付きで表示する', function () {
    createCinema('gion', '祇園ムビ');

    TicketType::create(['name' => '高校生以下', 'price' => 1000, 'display_order' => 2, 'condition' => null]);
    TicketType::create(['name' => TicketType::ADULT_NAME, 'price' => 2000, 'display_order' => 1, 'condition' => '18歳以上']);

    $html = $this->get(route('front.prices.index'))->assertOk()->getContent();

    expect($html)->toContain('18歳以上');
    // 大人（display_order=1）が高校生以下（display_order=2）より先に出る。
    expect(mb_strpos($html, TicketType::ADULT_NAME))->toBeLessThan(mb_strpos($html, '高校生以下'));
    expect($html)->toContain('2,000円');
    expect($html)->toContain('1,000円');
});

it('上映規格の追加料金を表示し、0円は「追加料金なし」と表示する', function () {
    createCinema('gion', '祇園ムビ');

    Format::create(['name' => '2D', 'default_surcharge' => 0]);
    Format::create(['name' => 'MOVI GRAND', 'default_surcharge' => 800]);

    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee('MOVI GRAND')
        ->assertSee('800円')
        ->assertSee(__('front.pages.prices.no_surcharge'));
});

it('座席の追加料金（surcharge > 0）のみを表示し、0円の座席種別は表示しない', function () {
    createCinema('gion', '祇園ムビ');

    SeatType::create(['name' => 'エグゼクティブ', 'surcharge' => 1000, 'display_class' => SeatDisplayClass::Executive]);
    SeatType::create(['name' => '一般', 'surcharge' => 0, 'display_class' => SeatDisplayClass::Standard]);

    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee('エグゼクティブ')
        ->assertSee('1,000円')
        ->assertDontSee('一般');
});

it('座席の追加料金が0件の場合は区画を表示しない', function () {
    createCinema('gion', '祇園ムビ');

    SeatType::create(['name' => '一般', 'surcharge' => 0, 'display_class' => SeatDisplayClass::Standard]);

    // メタ description には「座席の追加料金」という語句そのものが含まれるため、
    // 区画のみに出る列見出し（座席種別）の有無で判定する。
    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertDontSee(__('front.pages.prices.column_seat_type'));
});

it('割引の金額がPricingServiceの定数と一致して表示される', function () {
    createCinema('gion', '祇園ムビ');

    /* 数字の断片ではなく、組み立て後の文を丸ごと照合する（ペア割が券種価格のみの置き換えであることも含めて固定する。6.5.2-4）。 */
    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee(__('front.pages.prices.discount_late_show', [
            'hour' => PricingService::LATE_SHOW_FROM_HOUR,
            'amount' => __('front.reservation.yen', ['amount' => number_format(PricingService::LATE_SHOW_DISCOUNT)]),
        ]))
        ->assertSee(__('front.pages.prices.discount_pair', [
            'size' => PricingService::PAIR_SIZE,
            'amount' => __('front.reservation.yen', ['amount' => number_format(PricingService::PAIR_UNIT_PRICE)]),
        ]))
        ->assertSee('追加料金は別途')
        ->assertDontSee(':amount')
        ->assertDontSee(':size')
        ->assertDontSee(':hour');
});

it('当日券と支払方法の記載がある', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.prices.index'))
        ->assertOk()
        ->assertSee(__('front.pages.prices.box_office_note'));
});
