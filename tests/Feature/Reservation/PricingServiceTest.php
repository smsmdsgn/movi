<?php

use App\Enums\Discount;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\Theater;
use App\Models\TicketType;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use Carbon\CarbonImmutable;

/*
 * 金額の算出（13.4.5 / 6.5）。基本式・割引の適用単位・下限・無料鑑賞券を固定する。
 *
 * 旧12章 残課題15（割引額が金額を上回り unsignedInteger の列へ負の値が入る経路）の
 * 再発を防ぐため、券種価格を割引額より低くした場合を各割引について検証する。
 */

/**
 * 金額計算のフィクスチャ。
 *
 * @return array{screening: Screening, seats: list<Seat>}
 */
function pricingFixture(int $seatCount = 4, int $bookingSurcharge = 0, int $seatSurcharge = 0, string $startTime = '10:00'): array
{
    $theater = createTheater();
    $seatType = makeSeatsWithSurcharge($theater, $seatCount, $seatSurcharge);

    /** @var list<Seat> $seats */
    $seats = Seat::where('seat_type_id', $seatType->id)->orderBy('id')->get()->all();

    [$hour, $minute] = array_map('intval', explode(':', $startTime));
    [$screening] = makeScreenings($theater, [CarbonImmutable::now()->addDay()->setTime($hour, $minute)], $bookingSurcharge);

    return ['screening' => $screening, 'seats' => $seats];
}

/**
 * 名称と価格を指定して券種を作る（`m_ticket_types.name` は一意）。
 *
 * `firstOrCreate` にすると、既に同名の券種がある場合に `$price` が黙って無視され、
 * 金額の期待値の根拠が崩れる（「通るが検証していない」テストになる）。
 */
function makeTicketType(string $name, int $price): TicketType
{
    return TicketType::updateOrCreate(['name' => $name], ['price' => $price, 'display_order' => 1]);
}

/** 大人券種（ペア割の対象、6.5.2）。 */
function adultTicket(int $price = 2000): TicketType
{
    return makeTicketType(TicketType::ADULT_NAME, $price);
}

/** 大人以外の券種（ペア割の対象外）。 */
function studentTicket(int $price = 1500): TicketType
{
    return makeTicketType('学生', $price);
}

function pricing(): PricingService
{
    return app(PricingService::class);
}

/*
|--------------------------------------------------------------------------
| 基本式（6.5.4）
|--------------------------------------------------------------------------
*/

it('1席あたりの金額は券種価格・上映編成の追加料金・座席種別の追加料金の合計になる（6.5.4）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 1, bookingSurcharge: 300, seatSurcharge: 200);
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [$seats[0]->id => $adult->id]);

    expect($breakdown->subtotal())->toBe(2500)
        ->and($breakdown->discountAmount())->toBe(0)
        ->and($breakdown->total())->toBe(2500)
        ->and($breakdown->discount)->toBeNull()
        ->and($breakdown->seats[0]->amount())->toBe(2500);
});

it('小計は選択した全席の合計になる（6.5.4）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 3, bookingSurcharge: 100);
    $adult = adultTicket(2000);
    $student = studentTicket(1500);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $student->id,
        $seats[2]->id => $student->id,
    ]);

    // (2000+100) + (1500+100) + (1500+100)
    expect($breakdown->subtotal())->toBe(5300)
        ->and($breakdown->seatCount())->toBe(3);
});

it('座席を選択していない場合は0円の内訳を返す', function () {
    ['screening' => $screening] = pricingFixture();

    $breakdown = pricing()->calculate($screening, []);

    expect($breakdown->seats)->toBe([])
        ->and($breakdown->subtotal())->toBe(0)
        ->and($breakdown->total())->toBe(0)
        // 0席は「全額が賄われた」ではない（4.5.2 の決済スキップ条件）。
        ->and($breakdown->isFullyCovered())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| レイトショー（6.5.2）
|--------------------------------------------------------------------------
*/

it('20:00以降に始まる回は各席から500円を引く（6.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, startTime: '20:30');
    $student = studentTicket(1500);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $student->id,
        $seats[1]->id => $student->id,
    ]);

    // 割引は1席あたりに適用する（6.5.2-4）。2席なら1,000円引き。
    expect($breakdown->discount)->toBe(Discount::LateShow)
        ->and($breakdown->subtotal())->toBe(3000)
        ->and($breakdown->discountAmount())->toBe(1000)
        ->and($breakdown->total())->toBe(2000)
        ->and($breakdown->seats[0]->amount())->toBe(1000);
});

it('開始時刻でレイトショーの成否が決まる（6.5.2 の境界）', function (string $startTime, bool $applies) {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 1, startTime: $startTime);
    $student = studentTicket(1500);

    $breakdown = pricing()->calculate($screening, [$seats[0]->id => $student->id]);

    expect($breakdown->discount)->toBe($applies ? Discount::LateShow : null);
})->with([
    '19:59 は対象外' => ['19:59', false],
    '20:00 は対象' => ['20:00', true],
    '23:00 は対象' => ['23:00', true],
]);

it('レイトショーの割引額はその席の金額を超えない（6.5.2-6。旧残課題15）', function () {
    // A-07 で券種価格を500円未満へ引き下げた状態。
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 1, startTime: '21:00');
    $cheap = makeTicketType('特別割引', 300);

    $breakdown = pricing()->calculate($screening, [$seats[0]->id => $cheap->id]);

    expect($breakdown->discountAmount())->toBe(300)
        ->and($breakdown->total())->toBe(0)
        // unsignedInteger の列へ負の値を書き込まない。
        ->and($breakdown->seats[0]->amount())->toBe(0)
        ->and($breakdown->isFullyCovered())->toBeTrue();
});

it('追加料金は割引の対象に含まれる（1席あたりの金額から引く）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 1, bookingSurcharge: 400, startTime: '21:00');
    $cheap = makeTicketType('特別割引', 300);

    $breakdown = pricing()->calculate($screening, [$seats[0]->id => $cheap->id]);

    // 1席あたり700円に対し500円引き。券種価格だけを見て300円止まりにはしない。
    expect($breakdown->subtotal())->toBe(700)
        ->and($breakdown->discountAmount())->toBe(500)
        ->and($breakdown->total())->toBe(200);
});

/*
|--------------------------------------------------------------------------
| ペア割（6.5.2）
|--------------------------------------------------------------------------
*/

it('大人2枚は券種価格を1,500円に置き換える（6.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2);
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);

    expect($breakdown->discount)->toBe(Discount::Pair)
        ->and($breakdown->subtotal())->toBe(4000)
        ->and($breakdown->discountAmount())->toBe(1000)
        ->and($breakdown->total())->toBe(3000);
});

it('ペア割でも追加料金は別途加算する（6.5.3 / 6.5.2-4）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, bookingSurcharge: 300, seatSurcharge: 200);
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);

    // 1席 = 1,500円（置換後の券種価格）+ 300 + 200
    expect($breakdown->total())->toBe(4000)
        ->and($breakdown->seats[0]->amount())->toBe(2000);
});

it('大人が奇数枚のとき余りの1枚は通常料金とする（6.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 3);
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
        $seats[2]->id => $adult->id,
    ]);

    // 2枚分のみ割引。余りの1枚は2,000円のまま。
    expect($breakdown->discountAmount())->toBe(1000)
        ->and($breakdown->total())->toBe(5000);

    // どの席へ割引が付くかは座席IDの昇順で決定的（`amount` は席ごとに保存される。6.5.5）。
    expect($breakdown->seats[0]->amount())->toBe(1500)
        ->and($breakdown->seats[1]->amount())->toBe(1500)
        ->and($breakdown->seats[2]->amount())->toBe(2000);
});

it('大人1枚ではペア割が成立しない（6.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2);
    $adult = adultTicket(2000);
    $student = studentTicket(1500);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $student->id,
    ]);

    expect($breakdown->discount)->toBeNull()
        ->and($breakdown->discountAmount())->toBe(0)
        ->and($breakdown->total())->toBe(3500);
});

it('大人以外の券種はペア割の対象にしない（6.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2);
    $student = studentTicket(2000);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $student->id,
        $seats[1]->id => $student->id,
    ]);

    expect($breakdown->discount)->toBeNull()
        ->and($breakdown->total())->toBe(4000);
});

it('大人の価格が1,500円以下のときペア割を適用しない（6.5.2-5。旧残課題15）', function (int $price) {
    // A-07 で価格を引き下げると、1,500円への置換が値上げになる。
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2);
    $adult = adultTicket($price);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);

    expect($breakdown->discount)->toBeNull()
        ->and($breakdown->discountAmount())->toBe(0)
        ->and($breakdown->total())->toBe($price * 2);
})->with([
    '1,200円（置換すると値上げ）' => [1200],
    '1,500円（差額0）' => [1500],
]);

/*
|--------------------------------------------------------------------------
| 割引の重複（6.5.2-1）
|--------------------------------------------------------------------------
*/

it('双方が成立する場合は予約全体の割引額が大きい方を適用する（6.5.2-1）', function () {
    // 大人2枚・20:00以降。ペア割は1,000円引き、レイトショーは2席で1,000円引き…ではなく、
    // 大人を2,500円にしてペア割を2,000円引きにし、レイトショー（1,000円引き）を上回らせる。
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, startTime: '21:00');
    $adult = adultTicket(2500);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);

    expect($breakdown->discount)->toBe(Discount::Pair)
        ->and($breakdown->discountAmount())->toBe(2000)
        ->and($breakdown->total())->toBe(3000);
});

it('レイトショーの方が大きい場合はそちらを適用する（6.5.2-1）', function () {
    // 大人4枚・20:00以降。レイトショーは2,000円引き、ペア割は4枚で1,000円引き。
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 4, startTime: '21:00');
    $adult = adultTicket(1750);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
        $seats[2]->id => $adult->id,
        $seats[3]->id => $adult->id,
    ]);

    expect($breakdown->discount)->toBe(Discount::LateShow)
        ->and($breakdown->discountAmount())->toBe(2000)
        ->and($breakdown->total())->toBe(5000);
});

it('割引額が同じ場合はレイトショーを適用する', function () {
    // 大人2枚・20:00以降・大人2,000円。レイトショー1,000円引き、ペア割1,000円引き。
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, startTime: '21:00');
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);

    // 支払金額は同じだが、7.10-3 の表示と席ごとの配分を決定的にするための規約。
    expect($breakdown->discount)->toBe(Discount::LateShow)
        ->and($breakdown->total())->toBe(3000);
});

it('割引は重複適用しない（6.5.2-1）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, startTime: '21:00');
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);

    // 双方を足すと2,000円引きになるが、採るのは片方のみ。
    expect($breakdown->discountAmount())->toBe(1000);
});

/*
|--------------------------------------------------------------------------
| 無料鑑賞券（4.5.2）
|--------------------------------------------------------------------------
*/

it('無料鑑賞券は1席分の券種価格のみを無料にし、追加料金は残す（4.5.2-1 / 4.5.2-2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, bookingSurcharge: 300, seatSurcharge: 200);
    $adult = adultTicket(2000);
    $ticket = createFreeTicket();

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ], $ticket);

    expect($breakdown->freeTicketId)->toBe($ticket->id)
        ->and($breakdown->subtotal())->toBe(5000)
        ->and($breakdown->discountAmount())->toBe(2000)
        // 1席目は追加料金の500円のみが残る。
        ->and($breakdown->seats[0]->amount())->toBe(500)
        ->and($breakdown->total())->toBe(3000);
});

it('無料鑑賞券と割引は併用しない（4.5.2-6 / 6.5.2-2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, startTime: '21:00');
    $adult = adultTicket(2000);
    $ticket = createFreeTicket();

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ], $ticket);

    // レイトショーもペア割も成立するが、いずれも適用しない。
    expect($breakdown->discount)->toBeNull()
        ->and($breakdown->discountAmount())->toBe(2000)
        ->and($breakdown->total())->toBe(2000);
});

it('無料鑑賞券は券種価格が最も高い席へ充てる', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2);
    $adult = adultTicket(2000);
    $student = studentTicket(1500);
    $ticket = createFreeTicket();

    // 安い席を先に並べても、高い方（大人）が無料になる。
    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $student->id,
        $seats[1]->id => $adult->id,
    ], $ticket);

    expect($breakdown->discountAmount())->toBe(2000)
        ->and($breakdown->seats[1]->amount())->toBe(0)
        ->and($breakdown->total())->toBe(1500);
});

it('1席のみで追加料金が無い場合は支払金額が0円になり決済をスキップできる（4.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 1);
    $adult = adultTicket(2000);
    $ticket = createFreeTicket();

    $breakdown = pricing()->calculate($screening, [$seats[0]->id => $adult->id], $ticket);

    expect($breakdown->total())->toBe(0)
        ->and($breakdown->isFullyCovered())->toBeTrue();
});

it('追加料金が残る場合は決済をスキップできない（4.5.2「決済のスキップ条件」）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 1, bookingSurcharge: 300);
    $adult = adultTicket(2000);
    $ticket = createFreeTicket();

    $breakdown = pricing()->calculate($screening, [$seats[0]->id => $adult->id], $ticket);

    expect($breakdown->total())->toBe(300)
        ->and($breakdown->isFullyCovered())->toBeFalse();
});

it('使用できない無料鑑賞券は適用せず、通常の割引判定へ落とす（4.5.2-3 / 4.5.2-4）', function (string $case) {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, startTime: '21:00');
    $adult = adultTicket(2000);
    $ticket = createFreeTicket();

    // `used_at` は `$fillable` に無い（使用済みにするのは予約確定処理の責務）ため直接設定する。
    $case === 'expired'
        ? $ticket->update(['expires_at' => CarbonImmutable::now()->subDay()])
        : $ticket->forceFill(['used_at' => CarbonImmutable::now()])->save();

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ], $ticket);

    // 券は使わず、レイトショーが適用される。
    expect($breakdown->freeTicketId)->toBeNull()
        ->and($breakdown->discount)->toBe(Discount::LateShow)
        ->and($breakdown->total())->toBe(3000);
})->with([
    '期限切れ' => ['expired'],
    '使用済み' => ['used'],
]);

/*
|--------------------------------------------------------------------------
| 不変条件・異常系
|--------------------------------------------------------------------------
*/

it('どの経路でも席ごとの確定額と支払金額が負にならない（6.5.2-6）', function (int $price, string $startTime, bool $useFreeTicket) {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2, startTime: $startTime);
    $adult = adultTicket($price);

    $breakdown = pricing()->calculate(
        $screening,
        [$seats[0]->id => $adult->id, $seats[1]->id => $adult->id],
        $useFreeTicket ? createFreeTicket() : null,
    );

    expect($breakdown->total())->toBeGreaterThanOrEqual(0);

    foreach ($breakdown->seats as $seat) {
        expect($seat->amount())->toBeGreaterThanOrEqual(0)
            ->and($seat->discountAmount)->toBeLessThanOrEqual($seat->regularAmount);
    }
})->with([
    '1円・昼' => [1, '10:00', false],
    '1円・レイトショー' => [1, '21:00', false],
    '1円・無料鑑賞券' => [1, '10:00', true],
    '499円・レイトショー' => [499, '21:00', false],
    '10,000円・レイトショー' => [10000, '21:00', false],
]);

it('小計・割引額・支払金額は席ごとの内訳と一致する', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 3, bookingSurcharge: 100, startTime: '21:00');
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
        $seats[2]->id => $adult->id,
    ]);

    $sumRegular = array_sum(array_map(fn ($seat) => $seat->regularAmount, $breakdown->seats));
    $sumDiscount = array_sum(array_map(fn ($seat) => $seat->discountAmount, $breakdown->seats));

    expect($breakdown->subtotal())->toBe($sumRegular)
        ->and($breakdown->discountAmount())->toBe($sumDiscount)
        ->and($breakdown->total())->toBe($sumRegular - $sumDiscount);
});

it('内訳は座席IDの昇順で返す', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 3);
    $adult = adultTicket(2000);

    // 意図的に降順で渡す。
    $breakdown = pricing()->calculate($screening, [
        $seats[2]->id => $adult->id,
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);

    expect(array_map(fn ($seat) => $seat->seatId, $breakdown->seats))
        ->toBe([$seats[0]->id, $seats[1]->id, $seats[2]->id]);
});

it('存在しない座席IDを渡すと例外を投げる', function () {
    ['screening' => $screening] = pricingFixture();
    $adult = adultTicket(2000);

    pricing()->calculate($screening, [999_999 => $adult->id]);
})->throws(InvalidArgumentException::class);

it('存在しない券種IDを渡すと例外を投げる', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture();

    pricing()->calculate($screening, [$seats[0]->id => 999_999]);
})->throws(InvalidArgumentException::class);

it('金額を引数に取らず、券種マスタの価格を引き直す（17章）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 1);
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [$seats[0]->id => $adult->id]);
    expect($breakdown->total())->toBe(2000);

    // A-07 で価格を改定すると、以後の計算に即座に反映される（6.5.5 は確定後の予約の話）。
    $adult->update(['price' => 2400]);

    expect(pricing()->calculate($screening, [$seats[0]->id => $adult->id])->total())->toBe(2400);
});

it('割引の名称を日本語で返す（7.10-3）', function () {
    expect(Discount::LateShow->label())->toBe('レイトショー割引')
        ->and(Discount::Pair->label())->toBe('ペア割');
});

it('PriceBreakdown は席が無ければ決済スキップの対象にならない', function () {
    expect((new PriceBreakdown([]))->isFullyCovered())->toBeFalse();
});

it('上映回の一覧から渡しても遅延読み込みで落ちない（preventLazyLoading）', function () {
    ['seats' => $seats] = pricingFixture(seatCount: 1, bookingSurcharge: 300);
    $adult = adultTicket(2000);

    // `preventLazyLoading` は**複数行を水和したコレクション由来のモデルでのみ**違反を投げる
    // （`Builder::hydrate()` の `count($items) > 1`）。単一行の `find()` 由来では素通りするため、
    // この経路でしか `booking` の読み込み漏れを検出できない。
    makeScreenings(Theater::findOrFail($seats[0]->theater_id), [CarbonImmutable::now()->addDays(2)->setTime(12, 0)]);

    $screenings = Screening::query()->orderBy('id')->get();
    expect($screenings)->toHaveCount(2);

    $fromCollection = $screenings->firstOrFail();
    expect($fromCollection->relationLoaded('booking'))->toBeFalse();

    expect(pricing()->calculate($fromCollection, [$seats[0]->id => $adult->id])->total())->toBe(2300);
});

it('大人4枚では2組ぶんのペア割が成立する（6.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 4);
    $adult = adultTicket(2000);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
        $seats[2]->id => $adult->id,
        $seats[3]->id => $adult->id,
    ]);

    // 2組 = 4席すべてが1,500円。
    expect($breakdown->discount)->toBe(Discount::Pair)
        ->and($breakdown->discountAmount())->toBe(2000)
        ->and($breakdown->total())->toBe(6000);
});

it('ペア割の対象外の券種が混在しても通常料金のまま残る（6.5.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 3);
    $adult = adultTicket(2000);
    $student = studentTicket(1500);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
        $seats[2]->id => $student->id,
    ]);

    expect($breakdown->discount)->toBe(Discount::Pair)
        ->and($breakdown->discountAmount())->toBe(1000)
        // 学生席は1,500円のまま（ペア割の置換対象ではない）。
        ->and($breakdown->seats[2]->amount())->toBe(1500)
        ->and($breakdown->total())->toBe(4500);
});

it('大人が1,501円ならペア割が1円だけ成立する（6.5.2-5 の境界）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 2);
    $adult = adultTicket(1501);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
    ]);

    expect($breakdown->discount)->toBe(Discount::Pair)
        ->and($breakdown->discountAmount())->toBe(2)
        ->and($breakdown->total())->toBe(3000);
});

it('非大人席が混ざるとレイトショーがペア割を上回る（6.5.2-1 の典型例）', function () {
    // 大人2枚（ペア割1,000円引き）＋ 学生2枚。レイトショーなら4席×500 = 2,000円引き。
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 4, startTime: '21:00');
    $adult = adultTicket(2000);
    $student = studentTicket(1500);

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
        $seats[2]->id => $student->id,
        $seats[3]->id => $student->id,
    ]);

    expect($breakdown->discount)->toBe(Discount::LateShow)
        ->and($breakdown->discountAmount())->toBe(2000)
        ->and($breakdown->total())->toBe(5000);
});

it('無料鑑賞券が無料にするのは3席以上でも1席だけである（4.5.2-1）', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 3);
    $adult = adultTicket(2000);
    $ticket = createFreeTicket();

    $breakdown = pricing()->calculate($screening, [
        $seats[0]->id => $adult->id,
        $seats[1]->id => $adult->id,
        $seats[2]->id => $adult->id,
    ], $ticket);

    expect($breakdown->discountAmount())->toBe(2000)
        ->and($breakdown->total())->toBe(4000);

    $free = array_filter($breakdown->seats, fn ($seat) => $seat->discountAmount > 0);
    expect($free)->toHaveCount(1);
});

it('座席を選択していなければ無料鑑賞券を消費しない', function () {
    ['screening' => $screening] = pricingFixture();
    $ticket = createFreeTicket();

    expect(pricing()->calculate($screening, [], $ticket)->freeTicketId)->toBeNull();
});

it('券種価格が全席0円なら無料鑑賞券を消費しない', function () {
    ['screening' => $screening, 'seats' => $seats] = pricingFixture(seatCount: 1, bookingSurcharge: 300);
    $free = makeTicketType('招待', 0);
    $ticket = createFreeTicket();

    $breakdown = pricing()->calculate($screening, [$seats[0]->id => $free->id], $ticket);

    // 無料にできる券種価格が無い。券を消費せず、追加料金だけが残る。
    expect($breakdown->freeTicketId)->toBeNull()
        ->and($breakdown->total())->toBe(300);
});
