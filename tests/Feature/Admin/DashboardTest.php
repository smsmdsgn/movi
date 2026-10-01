<?php

use App\Enums\AdminRole;
use App\Enums\ContactType;
use App\Enums\PostStatus;
use App\Enums\ReservationStatus;
use App\Enums\SeatDisplayClass;
use App\Livewire\Admin\Dashboard\Index;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatType;
use App\Models\Theater;
use App\Models\TicketType;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    // 2026-10-14 は水曜日。日付の境界を固定する。
    $this->travelTo('2026-10-14 12:00:00');
});

/**
 * 指定の館に、座席 `$seatCount` 席のシアターと、`$startsAt` 開始の上映回を作る。
 *
 * @return array{screening: Screening, seats: list<Seat>}
 */
function dashboardScreening(Cinema $cinema, string $startsAt, int $seatCount = 1): array
{
    static $theaterNumber = 0;
    $theaterNumber++;

    $theater = Theater::create([
        'cinema_id' => $cinema->id,
        'number' => $theaterNumber,
        'name' => $theaterNumber.'番シアター',
    ]);
    $seatType = SeatType::firstOrCreate(
        ['name' => '一般'],
        ['surcharge' => 0, 'display_class' => SeatDisplayClass::Standard],
    );

    $seats = [];
    for ($i = 1; $i <= $seatCount; $i++) {
        $seats[] = Seat::create([
            'theater_id' => $theater->id,
            'seat_type_id' => $seatType->id,
            'row_label' => 'A',
            'seat_number' => sprintf('%02d', $i),
            'grid_row' => 1,
            'grid_col' => $i,
        ]);
    }

    $screening = createScreeningForTheater($theater);
    $screening->update(['starts_at' => $startsAt, 'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt) + 7200)]);

    return ['screening' => $screening, 'seats' => $seats];
}

/**
 * 上映回の座席 `$seatIndex` 番目に予約を1件作る。
 *
 * @param  array{screening: Screening, seats: list<Seat>}  $ctx
 */
function dashboardReservation(array $ctx, int $seatIndex = 0, ReservationStatus $status = ReservationStatus::Paid, bool $checkedIn = false): void
{
    // 6.4.2: pending・expired は t_reservation_seats を持たない。
    if (in_array($status, [ReservationStatus::Pending, ReservationStatus::Expired], true)) {
        Reservation::create([
            'reservation_no' => nextTestReservationNo(),
            'guest_name' => '予約 太郎',
            'guest_name_kana' => 'ヨヤク タロウ',
            'contact_type' => ContactType::Guest,
            'guest_email' => 'guest@example.test',
            'guest_phone' => '09000000000',
            'screening_id' => $ctx['screening']->id,
            'status' => $status,
            'total_amount' => 2000,
            'expires_at' => now()->addMinutes(10),
        ]);

        return;
    }

    $ticketTypeId = TicketType::query()->value('id') ?? createTicketType()->id;

    $reservation = makeSeatedReservation(
        ['screening' => $ctx['screening'], 'seat' => $ctx['seats'][$seatIndex], 'ticketTypeId' => $ticketTypeId],
        ['status' => $status],
        releaseSeats: $status === ReservationStatus::Cancelled,
    );

    if ($checkedIn) {
        $reservation->checked_in_at = now();
        $reservation->save();
    }
}

/**
 * @return array<string, mixed>
 */
function dashboardSummary(Index|Testable $component): array
{
    return $component->viewData('summary');
}

it('super-admin は未選択なら全館の合計、館を選ぶとその館だけを見る', function () {
    $cinemaA = createCinema(name: 'A館');
    $cinemaB = createCinema(name: 'B館');
    $a = dashboardScreening($cinemaA, '2026-10-14 10:00:00');
    $b = dashboardScreening($cinemaB, '2026-10-14 11:00:00', 2);
    dashboardReservation($a);
    dashboardReservation($b);
    dashboardReservation($b, 1);

    $this->actingAs(createAdmin(), 'admin');

    $component = Livewire::test(Index::class)
        ->assertSee('A館')
        ->assertSee('B館');
    expect(dashboardSummary($component))->toMatchArray(['screenings' => 2, 'reservations' => 3, 'seats' => 3]);

    $component->set('selectedCinemaId', $cinemaB->id);
    expect(dashboardSummary($component))->toMatchArray(['screenings' => 1, 'reservations' => 2, 'seats' => 2]);
    expect($component->viewData('screenings')->pluck('id')->all())->toBe([$b['screening']->id]);
});

it('cinema-admin は自館分のみを見て、館セレクタは出ない', function () {
    $own = createCinema(name: '自館');
    $other = createCinema(name: '他館');
    $ownCtx = dashboardScreening($own, '2026-10-14 10:00:00');
    $otherCtx = dashboardScreening($other, '2026-10-14 11:00:00');
    dashboardReservation($ownCtx);
    dashboardReservation($otherCtx);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own), 'admin');

    $component = Livewire::test(Index::class)
        ->assertViewHas('canSelectCinema', false)
        ->assertDontSee(__('admin.common.all_cinemas'));
    expect(dashboardSummary($component))->toMatchArray(['screenings' => 1, 'reservations' => 1, 'seats' => 1]);
    expect($component->viewData('screenings')->pluck('id')->all())->toBe([$ownCtx['screening']->id]);
});

it('所属館が未設定の cinema-admin は403になる', function () {
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin), 'admin')
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

it('cinema-admin が他館を選んだ値を送っても自館の集計のまま（17.2.1）', function () {
    $own = createCinema(name: '自館');
    $other = createCinema(name: '他館');
    dashboardReservation(dashboardScreening($own, '2026-10-14 10:00:00'));
    dashboardReservation(dashboardScreening($other, '2026-10-14 11:00:00'));
    createPost(['cinema_id' => $other->id, 'status' => PostStatus::Draft, 'published_at' => null]);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own), 'admin');

    // targetCinemaId() は cinema-admin の selectedCinemaId を無視して自館へ固定する。
    $component = Livewire::test(Index::class)->set('selectedCinemaId', $other->id);
    expect(dashboardSummary($component))->toMatchArray(['screenings' => 1, 'reservations' => 1]);
    expect($component->viewData('unpublishedPostCount'))->toBe(0);
});

it('gate ロールは集計を取得できない（17.1.3）', function () {
    // ルートの AuthorizeAdminScreen は /livewire/update では働かないため、
    // コンポーネント自体の判定を確かめる。
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate, createCinema()), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('予約件数・入場済み件数は paid のみ、予約座席数は占有中のみを数える', function () {
    $cinema = createCinema();
    $ctx = dashboardScreening($cinema, '2026-10-14 10:00:00', 4);
    dashboardReservation($ctx, 0, ReservationStatus::Paid, checkedIn: true);
    dashboardReservation($ctx, 1, ReservationStatus::Cancelled, checkedIn: true);
    dashboardReservation($ctx, 2, ReservationStatus::Pending);
    dashboardReservation($ctx, 3, ReservationStatus::Expired);

    $this->actingAs(createAdmin(), 'admin');

    $summary = dashboardSummary(Livewire::test(Index::class));
    expect($summary)->toMatchArray(['reservations' => 1, 'checkedIn' => 1, 'checkInRate' => 100]);
    // cancelled の予約座席は解放済み。pending・expired は予約座席を持たない（6.4.2）。
    expect($summary['seats'])->toBe(1);
});

it('入場率は入場済み件数 ÷ 予約件数（切り捨て）で、予約0件なら出さない', function () {
    $cinema = createCinema();
    $ctx = dashboardScreening($cinema, '2026-10-14 10:00:00', 4);

    $this->actingAs(createAdmin(), 'admin');

    $component = Livewire::test(Index::class);
    expect(dashboardSummary($component)['checkInRate'])->toBeNull();
    $component->assertSee(__('admin.dashboard.check_in_rate_none'));

    dashboardReservation($ctx, 0, checkedIn: true);
    dashboardReservation($ctx, 1);
    dashboardReservation($ctx, 2);
    dashboardReservation($ctx, 3);

    expect(dashboardSummary(Livewire::test(Index::class))['checkInRate'])->toBe(25);
});

it('本日の境界: 前日 23:59 と翌日 00:00 開始の上映回は集計にも一覧にも入らない', function () {
    $cinema = createCinema();
    $before = dashboardScreening($cinema, '2026-10-13 23:59:00');
    $start = dashboardScreening($cinema, '2026-10-14 00:00:00');
    $end = dashboardScreening($cinema, '2026-10-14 23:59:00');
    $after = dashboardScreening($cinema, '2026-10-15 00:00:00');
    foreach ([$before, $start, $end, $after] as $ctx) {
        dashboardReservation($ctx);
    }

    $this->actingAs(createAdmin(), 'admin');

    $component = Livewire::test(Index::class);
    expect(dashboardSummary($component))->toMatchArray(['screenings' => 2, 'reservations' => 2, 'seats' => 2]);
    expect($component->viewData('screenings')->pluck('id')->all())
        ->toBe([$start['screening']->id, $end['screening']->id]);
});

it('推移は本日を含む7日分を上映日ごとに数え、予約の無い日は0件になる', function () {
    $cinema = createCinema();
    $yesterday = dashboardScreening($cinema, '2026-10-13 10:00:00', 2);
    $today = dashboardScreening($cinema, '2026-10-14 10:00:00');
    $firstDay = dashboardScreening($cinema, '2026-10-08 23:59:00');
    // 予約の作成日時（購入日）は本日。上映日（昨日）で数えられること。
    dashboardReservation($yesterday);
    dashboardReservation($yesterday, 1);
    dashboardReservation($today);
    dashboardReservation($firstDay);

    $this->actingAs(createAdmin(), 'admin');

    $trend = collect(Livewire::test(Index::class)->viewData('trend'))
        ->mapWithKeys(fn ($row) => [$row['date']->toDateString() => $row['count']]);

    expect($trend->all())->toBe([
        '2026-10-08' => 1,
        '2026-10-09' => 0,
        '2026-10-10' => 0,
        '2026-10-11' => 0,
        '2026-10-12' => 0,
        '2026-10-13' => 2,
        '2026-10-14' => 1,
    ]);
});

it('未公開のお知らせは 下書き・公開日時なし・公開予定 を数え、公開済みは数えない', function () {
    createPost(['status' => PostStatus::Draft, 'published_at' => null]);
    createPost(['status' => PostStatus::Published, 'published_at' => null]);
    createPost(['status' => PostStatus::Published, 'published_at' => now()->addDay()]);
    createPost(['status' => PostStatus::Published, 'published_at' => now()->subDay()]);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)->assertViewHas('unpublishedPostCount', 3);
});

it('cinema-admin の未公開のお知らせは 自館向けと全館共通 を数え、他館向けは数えない', function () {
    $own = createCinema();
    $other = createCinema();
    createPost(['cinema_id' => $own->id, 'status' => PostStatus::Draft, 'published_at' => null]);
    createPost(['cinema_id' => null, 'status' => PostStatus::Draft, 'published_at' => null]);
    createPost(['cinema_id' => $other->id, 'status' => PostStatus::Draft, 'published_at' => null]);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own), 'admin');

    Livewire::test(Index::class)->assertViewHas('unpublishedPostCount', 2);
});

it('推移の帯は最大件数に対する比で、最大が0なら幅0になる', function () {
    $cinema = createCinema();
    $ctx = dashboardScreening($cinema, '2026-10-14 10:00:00', 2);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)->assertDontSee('width: 100%', false)->assertSee('width: 0%', false);

    dashboardReservation($ctx);

    Livewire::test(Index::class)->assertSee('width: 100%', false);
});
