<?php

use App\Enums\SeatSelectionState;
use App\Livewire\Front\Reservation\SeatSelection;
use App\Models\SeatLock;
use App\Models\User;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * 座席選択（P-31、7.6）。座席の状態表示・ロックの取得と解放・7.17 の文言の出し分けを固定する。
 * ロックの取得条件そのものは `tests/Feature/Reservation/SeatLockServiceTest.php` が担保する。
 */
function seatLockService(): SeatLockService
{
    return app(SeatLockService::class);
}

/**
 * レンダリング済みHTMLから、指定した座席の `data-seat-state` を取り出す。
 */
function seatStateOf(string $html, int $seatId): ?string
{
    if (preg_match('/wire:key="seat-'.$seatId.'"[^>]*?data-seat-state="([^"]+)"/s', $html, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

it('座席選択ページが上映情報と座席表を表示し、クロール対象外とする（19.3-6）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();

    $this->get(route('front.reservation.seats', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('テスト作品')
        ->assertSee($theater->name)
        ->assertSee(__('front.reservation.screen'))
        ->assertSee($seats[0]->seat_number)
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('上映情報に 7.6.1-1 の5項目をすべて表示する（screening-summary）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();

    $startsAt = $screening->starts_at;
    $datetime = __('front.reservation.screening.datetime', [
        'date' => $startsAt->format('Y/n/j'),
        'weekday' => __('front.schedule.weekdays')[$startsAt->dayOfWeek],
        'time' => $startsAt->format('H:i'),
    ]);

    // 共通部品（P-31・P-32 が使う）のため、項目が1つ落ちても他のテストでは気づけない。
    $this->get(route('front.reservation.seats', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee($screening->booking->movie->title)
        ->assertSee($theater->cinema->name)
        ->assertSee($theater->name)
        ->assertSee($datetime)
        ->assertSee($screening->booking->format->name);
});

it('上映回IDはクライアントから差し替えられない（4.3.10）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();
    $other = createScreeningForTheater($theater);

    // `#[Locked]` が外れると、予約フロー全画面（ResolvesScreening の利用側）が同時に穴になる。
    Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->set('screeningId', $other->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('選択不可の座席は操作させないがフォーカスは当てられる（7.6.4-3 / 4.3.9）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    seatLockService()->acquire($screening, $seats[0], 'session:other');

    $html = Livewire::test(SeatSelection::class, ['screening' => $screening])->html();

    // 選択不可の座席には wire:click を出さず、disabled ではなく aria-disabled を付ける。
    expect($html)->toMatch('/wire:key="seat-'.$seats[0]->id.'"(?:(?!<\/button>).)*aria-disabled="true"/s');
    expect($html)->not->toMatch('/wire:key="seat-'.$seats[0]->id.'"(?:(?!<\/button>).)*wire:click/s');
    expect($html)->not->toMatch('/\sdisabled[\s=>]/');
});

it('座席表は10秒間隔のポーリングで更新する（6.4.3-1）', function () {
    ['screening' => $screening] = makeReservationFixture();

    Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->assertSee('wire:poll.10s="refreshSeatMap"', escape: false);
});

it('存在しない上映回は404を返す', function () {
    $this->get(route('front.reservation.seats', ['id' => 999_999]))->assertNotFound();
});

it('上映回の館をヘッダー・パンくずの館として確定させる（4.1.3-1）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();
    createCinema('other-cinema', '別の館');

    // ヘッダーの劇場切替は全館名を <option> に出すため、館名の有無だけでは検証にならない。
    // パンくずの該当要素（既存テストと同じ data-testid）で判定する。
    $this->withSession(['cinema_slug' => 'other-cinema'])
        ->get(route('front.reservation.seats', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('data-testid="cinema-name">'.$theater->cinema->name.'<', escape: false)
        ->assertSessionHas('cinema_slug', $theater->cinema->slug)
        ->assertCookie('cinema_slug', $theater->cinema->slug);
});

it('座席をクリックするとロックを取得し、再クリックで解放する（7.6.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    $component = Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->call('toggle', $seats[0]->id);

    $lock = SeatLock::where('screening_id', $screening->id)->sole();
    expect($lock->seat_id)->toBe($seats[0]->id);
    expect($lock->holder_key)->toStartWith('session:');
    expect(seatStateOf($component->html(), $seats[0]->id))->toBe(SeatSelectionState::Selected->value);
    $component->assertSee($seats[0]->displayName());

    $component->call('toggle', $seats[0]->id);

    expect(SeatLock::count())->toBe(0);
    expect(seatStateOf($component->html(), $seats[0]->id))->toBe(SeatSelectionState::Selectable->value);
});

it('会員の保持者キーは user:{id} とする（13.3）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SeatSelection::class, ['screening' => $screening])
        ->call('toggle', $seats[0]->id);

    expect(SeatLock::sole()->holder_key)->toBe('user:'.$user->id);
});

it('他者がロック中の座席は選択できず、7.17 の文言を表示する', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    seatLockService()->acquire($screening, $seats[0], 'session:other');

    $component = Livewire::test(SeatSelection::class, ['screening' => $screening]);

    expect(seatStateOf($component->html(), $seats[0]->id))->toBe(SeatSelectionState::Occupied->value);

    // 選択不可の座席には wire:click を出さないため、通常の操作では到達しない経路（改ざん）。
    $component->call('toggle', $seats[0]->id)
        ->assertSee(__('front.reservation.errors.lock_failed'));

    expect(SeatLock::where('seat_id', $seats[0]->id)->sole()->holder_key)->toBe('session:other');
});

it('決済済みの座席は選択できない状態で描画される（6.4.2）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    createReservationSeat($screening->id, $seats[0]->id, createTicketType()->id);

    $html = Livewire::test(SeatSelection::class, ['screening' => $screening])->html();

    expect(seatStateOf($html, $seats[0]->id))->toBe(SeatSelectionState::Occupied->value);
    expect(seatStateOf($html, $seats[1]->id))->toBe(SeatSelectionState::Selectable->value);
});

it('使用不可の座席は座席表に描画しない（6.2 / seat-map）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    $seats[0]->update(['is_available' => false]);

    $html = Livewire::test(SeatSelection::class, ['screening' => $screening])->html();

    expect(seatStateOf($html, $seats[0]->id))->toBeNull();
    expect(seatStateOf($html, $seats[1]->id))->toBe(SeatSelectionState::Selectable->value);
});

it('8席を超えて選択できない（4.3.4 / 17.8-2）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture(SeatLockService::MAX_SEATS_PER_HOLDER + 1);

    $component = Livewire::test(SeatSelection::class, ['screening' => $screening]);

    foreach (range(0, SeatLockService::MAX_SEATS_PER_HOLDER - 1) as $index) {
        $component->call('toggle', $seats[$index]->id);
    }

    $component->call('toggle', $seats[SeatLockService::MAX_SEATS_PER_HOLDER]->id)
        ->assertSee(__('front.reservation.errors.seat_limit', ['max' => SeatLockService::MAX_SEATS_PER_HOLDER]));

    expect(SeatLock::count())->toBe(SeatLockService::MAX_SEATS_PER_HOLDER);
});

it('販売期間外の上映回は座席表を出さず、7.17 の文言のみを表示する（4.3.1）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    $screening->update([
        'starts_at' => CarbonImmutable::now()->subHour(),
        'ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.out_of_sale'))
        ->assertDontSee(__('front.reservation.screen'))
        ->call('toggle', $seats[0]->id);

    expect(SeatLock::count())->toBe(0);
});

it('入場時に他の上映回のロックを解放する（4.3.4）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();
    $holderKey = seatLockService()->holderKey();

    $other = createScreeningForTheater($theater);
    $other->update([
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(15, 0),
        'ends_at' => CarbonImmutable::now()->addDay()->setTime(17, 0),
    ]);
    seatLockService()->acquire($other, $seats[0], $holderKey);

    Livewire::test(SeatSelection::class, ['screening' => $screening]);

    expect(SeatLock::count())->toBe(0);
});

it('販売期間外の回を開いても他の上映回のロックは解放しない（4.3.4 / 4.3.9）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();
    $holderKey = seatLockService()->holderKey();

    seatLockService()->acquire($screening, $seats[0], $holderKey);

    // 開始済みの回（古いリンク・履歴からの到達）を開く。
    $started = createScreeningForTheater($theater);
    $started->update([
        'starts_at' => CarbonImmutable::now()->subHour(),
        'ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    Livewire::test(SeatSelection::class, ['screening' => $started])
        ->assertSee(__('front.reservation.errors.out_of_sale'));

    expect(SeatLock::where('screening_id', $screening->id)->count())->toBe(1);
});

it('別の上映回のロックを保持している場合は専用の案内を表示する（4.3.9）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture();

    $component = Livewire::test(SeatSelection::class, ['screening' => $screening]);

    // 画面を開いた後に、別タブで他の上映回の座席を選択した状態を作る。
    $other = createScreeningForTheater($theater);
    $other->update([
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(15, 0),
        'ends_at' => CarbonImmutable::now()->addDay()->setTime(17, 0),
    ]);
    seatLockService()->acquire($other, $seats[1], seatLockService()->holderKey());

    $component->call('toggle', $seats[0]->id)
        ->assertSee(__('front.reservation.errors.other_screening'));

    expect(SeatLock::where('screening_id', $screening->id)->count())->toBe(0);
});

it('ポーリング時に保持座席が減っていればロックの期限切れを知らせる（6.4.1-3 / 7.17）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    $component = Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->call('toggle', $seats[0]->id)
        ->call('refreshSeatMap')
        ->assertDontSee(__('front.reservation.errors.lock_expired'));

    $this->travel(SeatLockService::LOCK_MINUTES + 1)->minutes();

    $component->call('refreshSeatMap')
        ->assertSee(__('front.reservation.errors.lock_expired'));
});

it('別タブで他の上映回へ移った場合、ポーリングは期限切れではなく専用の案内を出す（4.3.9）', function () {
    ['screening' => $screening, 'seats' => $seats, 'theater' => $theater] = makeReservationFixture(2);

    $component = Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->call('toggle', $seats[0]->id);

    // 別タブで他の上映回の P-31 を開いた状態（そちらの mount() がこの回のロックを解放する）。
    $other = createScreeningForTheater($theater);
    $other->update([
        'starts_at' => CarbonImmutable::now()->addDay()->setTime(15, 0),
        'ends_at' => CarbonImmutable::now()->addDay()->setTime(17, 0),
    ]);
    Livewire::test(SeatSelection::class, ['screening' => $other])
        ->call('toggle', $seats[1]->id);

    $component->call('refreshSeatMap')
        ->assertSee(__('front.reservation.errors.other_screening'))
        ->assertDontSee(__('front.reservation.errors.lock_expired'));
});

it('座席を選択せずに次へ進むことはできない', function () {
    ['screening' => $screening] = makeReservationFixture();

    Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->call('proceed')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.no_seats'));
});

it('上映回が削除されても 7.17 の文言を返し、例外にしない（4.3.10）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    $component = Livewire::test(SeatSelection::class, ['screening' => $screening]);

    // A-09 は有効な座席ロックがある回を削除しないため、1席も選んでいない利用者が
    // 画面を開いたままの場合にこの状態になる（旧12章 残課題23）。
    $screening->delete();

    $component->call('toggle', $seats[0]->id)
        ->assertSee(__('front.reservation.errors.out_of_sale'))
        ->assertDontSee(__('front.reservation.screen'))
        ->call('refreshSeatMap')
        ->call('proceed')
        ->assertNoRedirect();

    expect(SeatLock::count())->toBe(0);
});

it('数値でない座席IDを送っても 7.17 の文言を返し、例外にしない（4.3.10）', function () {
    ['screening' => $screening] = makeReservationFixture();

    Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->call('toggle', 'seat-1')
        ->assertSee(__('front.reservation.errors.lock_failed'));

    expect(SeatLock::count())->toBe(0);
});

it('座席を選択して次へ進むと同意画面（P-32）へ遷移する（7.18）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();

    Livewire::test(SeatSelection::class, ['screening' => $screening])
        ->call('toggle', $seats[0]->id)
        ->call('proceed')
        ->assertRedirect(route('front.reservation.agreement', ['id' => $screening->id]));
});
