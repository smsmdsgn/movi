<?php

use App\Enums\AdminRole;
use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Enums\SeatDisplayClass;
use App\Livewire\Admin\Screenings\Index;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\SeatType;
use App\Models\Theater;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * A-09（上映回）の検証。13.6 T-05（期間外・規格不一致・インターバル不足で登録が
 * 拒否されること）と T-02（`cinema-admin` が他館のデータを更新できないこと）、
 * および 6.2 制約1（予約が存在する上映回は削除できない）を対象とする。
 */

/**
 * 上映回を登録できる最小構成（館・規格・シアター・作品・上映編成）を1式作る。
 * `runtime_minutes` は 4.8.3-3 の終了時刻の自動計算を検証できるよう明示する。
 *
 * @return array{cinema: Cinema, format: Format, theater: Theater, movie: Movie, booking: Booking}
 */
function makeScreeningFixture(int $runtimeMinutes = 100, int $theaterNumber = 1): array
{
    static $tmdbId = 970_000;

    $cinema = createCinema();
    $format = Format::firstOrCreate(['name' => '2D'], ['default_surcharge' => 0]);

    $theater = Theater::create([
        'cinema_id' => $cinema->id,
        'number' => $theaterNumber,
        'name' => $theaterNumber.'番シアター',
    ]);
    $theater->formats()->sync([$format->id]);

    $movie = Movie::create([
        'tmdb_id' => ++$tmdbId,
        'title' => 'テスト作品',
        'synopsis' => 'テスト用のあらすじ',
        'runtime_minutes' => $runtimeMinutes,
        'released_on' => now()->subYear(),
    ]);
    $movie->formats()->sync([$format->id]);

    $booking = Booking::create([
        'cinema_id' => $cinema->id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now()->startOfDay(),
        'ends_on' => now()->startOfDay()->addDays(7),
        'surcharge' => 0,
    ]);

    return compact('cinema', 'format', 'theater', 'movie', 'booking');
}

/** `datetime-local` の入力形式（コンポーネントが受け付ける表記）へ変換する。 */
function screeningInput(string $modifier): string
{
    return now()->startOfDay()->modify($modifier)->format('Y-m-d\TH:i');
}

/** 決済済みの予約を1件作る（6.2 制約1 の検証用。座席は伴わない）。 */
function makePaidReservation(Screening $screening): Reservation
{
    return Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'guest_name' => 'テスト太郎',
        'guest_name_kana' => 'テストタロウ',
        'contact_type' => ContactType::Guest,
        'guest_email' => 'guest@example.com',
        'screening_id' => $screening->id,
        'status' => ReservationStatus::Paid,
        'total_amount' => 2000,
    ]);
}

it('super-admin は一覧を閲覧でき、新規登録ボタンが表示される（4.8.2）', function () {
    $fixture = makeScreeningFixture();
    Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(10),
        'ends_at' => now()->startOfDay()->addHours(12),
    ]);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.screening.index'))
        ->assertOk()
        ->assertSee($fixture['movie']->title)
        ->assertSee(__('admin.screening.actions.create'));
});

it('cinema-admin は自館の上映回の一覧へ到達できる（4.8.2 / 4.8.5）', function () {
    $own = makeScreeningFixture();
    $other = makeScreeningFixture(theaterNumber: 2);

    Screening::create([
        'booking_id' => $own['booking']->id,
        'theater_id' => $own['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(10),
        'ends_at' => now()->startOfDay()->addHours(12),
    ]);
    Screening::create([
        'booking_id' => $other['booking']->id,
        'theater_id' => $other['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(18),
        'ends_at' => now()->startOfDay()->addHours(20),
    ]);

    // AuthorizeAdminScreen ミドルウェアと view-admin-screen Gate を通る経路を検証する
    // （Livewire::test は両者を通らない）。
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $own['cinema']), 'admin')
        ->get(route('admin.screening.index'))
        ->assertOk()
        ->assertSee($own['theater']->name)
        ->assertDontSee($other['theater']->name);
});

it('一覧は上映日で絞り込まれ、既定は本日である（4.8.6追記表）', function () {
    $fixture = makeScreeningFixture();

    $today = Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(10),
        'ends_at' => now()->startOfDay()->addHours(12),
    ]);
    $tomorrow = Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->startOfDay()->addDay()->addHours(15),
        'ends_at' => now()->startOfDay()->addDay()->addHours(17),
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->assertSet('filterDate', now()->toDateString())
        ->assertSee($today->starts_at->format('H:i'))
        ->assertDontSee($tomorrow->starts_at->format('H:i'))
        ->set('filterDate', now()->addDay()->toDateString())
        ->assertSee($tomorrow->starts_at->format('H:i'));
});

it('上映回を登録でき、登録者が記録される（4.8.3 / 4.8.4-7）', function () {
    $fixture = makeScreeningFixture();
    $admin = createAdmin();

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('starts_at', screeningInput('+10 hours'))
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('theater_id', (string) $fixture['theater']->id)
        ->call('save')
        ->assertHasNoErrors();

    $screening = Screening::sole();

    expect($screening->theater_id)->toBe($fixture['theater']->id)
        ->and($screening->created_by_admin_id)->toBe($admin->id);
});

it('開始日時を入力すると終了時刻が「上映時間＋予告編15分」で自動計算される（4.8.3-3）', function () {
    $fixture = makeScreeningFixture(runtimeMinutes: 100);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('starts_at', screeningInput('+10 hours'))
        // 10:00 + 100分 + 15分 = 11:55
        ->assertSet('ends_at', screeningInput('+11 hours +55 minutes'));
});

it('終了時刻は手動で上書きできる（4.8.3-3）', function () {
    $fixture = makeScreeningFixture(runtimeMinutes: 100);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('starts_at', screeningInput('+10 hours'))
        ->set('theater_id', (string) $fixture['theater']->id)
        ->set('ends_at', screeningInput('+13 hours'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Screening::sole()->ends_at->format('H:i'))->toBe('13:00');
});

it('親の上映期間外の上映回は登録できない（T-05 / 4.8.3-1）', function () {
    $fixture = makeScreeningFixture();

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('theater_id', (string) $fixture['theater']->id)
        // 上映編成は本日〜7日後。8日後は範囲外。
        ->set('starts_at', screeningInput('+8 days +10 hours'))
        ->set('ends_at', screeningInput('+8 days +12 hours'))
        ->call('save')
        ->assertHasErrors('starts_at');

    expect(Screening::count())->toBe(0);
});

it('上映規格に対応しないシアターには登録できない（T-05 / 4.8.3-2）', function () {
    $fixture = makeScreeningFixture();

    // 同じ館に、編成の規格に対応しないシアターを追加する。
    $unsupported = Theater::create([
        'cinema_id' => $fixture['cinema']->id,
        'number' => 9,
        'name' => '9番シアター',
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('theater_id', (string) $unsupported->id)
        ->set('starts_at', screeningInput('+10 hours'))
        ->set('ends_at', screeningInput('+12 hours'))
        ->call('save')
        ->assertHasErrors('theater_id');

    expect(Screening::count())->toBe(0);
});

it('別の館のシアターには登録できない（4.8.3-5）', function () {
    $fixture = makeScreeningFixture();
    $other = makeScreeningFixture(theaterNumber: 2);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('theater_id', (string) $other['theater']->id)
        ->set('starts_at', screeningInput('+10 hours'))
        ->set('ends_at', screeningInput('+12 hours'))
        ->call('save')
        ->assertHasErrors('theater_id');

    expect(Screening::count())->toBe(0);
});

it('前の回の終了から30分空いていない上映回は登録できない（T-05 / 4.8.3-4）', function (string $starts, string $ends) {
    $fixture = makeScreeningFixture();

    Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(13),
        'ends_at' => now()->startOfDay()->addHours(15),
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('theater_id', (string) $fixture['theater']->id)
        ->set('starts_at', screeningInput($starts))
        ->set('ends_at', screeningInput($ends))
        ->call('save')
        ->assertHasErrors('starts_at');

    expect(Screening::count())->toBe(1);
})->with([
    // 既存は 13:00〜15:00。前後いずれの隣接も30分未満なら拒否される。
    '前の回として詰めすぎ（12:45終了）' => ['+11 hours', '+12 hours +45 minutes'],
    '後の回として詰めすぎ（15:20開始）' => ['+15 hours +20 minutes', '+17 hours'],
]);

it('インターバル判定は別の上映編成の回もまたいで働く（4.8.3-4 / 4.8.6追記表の排他制御）', function () {
    $fixture = makeScreeningFixture();

    // 同じ館・同じシアターに、別の作品の上映編成をもう1本組む（4.8.3 は t_bookings に
    // theater_id を持たせないため、1つのシアターに複数の編成の回が並ぶ）。
    $otherMovie = Movie::create([
        'tmdb_id' => 960_001,
        'title' => '別のテスト作品',
        'synopsis' => 'テスト用のあらすじ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYear(),
    ]);
    $otherMovie->formats()->sync([$fixture['format']->id]);

    $otherBooking = Booking::create([
        'cinema_id' => $fixture['cinema']->id,
        'movie_id' => $otherMovie->id,
        'format_id' => $fixture['format']->id,
        'starts_on' => now()->startOfDay(),
        'ends_on' => now()->startOfDay()->addDays(7),
        'surcharge' => 0,
    ]);

    Screening::create([
        'booking_id' => $otherBooking->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(13),
        'ends_at' => now()->startOfDay()->addHours(15),
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('theater_id', (string) $fixture['theater']->id)
        ->set('starts_at', screeningInput('+15 hours +20 minutes'))
        ->set('ends_at', screeningInput('+17 hours'))
        ->call('save')
        ->assertHasErrors('starts_at');

    expect(Screening::count())->toBe(1);
});

it('ちょうど30分空いていれば登録できる（4.8.3-4 の境界）', function (string $starts, string $ends) {
    $fixture = makeScreeningFixture();

    Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(13),
        'ends_at' => now()->startOfDay()->addHours(15),
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('theater_id', (string) $fixture['theater']->id)
        ->set('starts_at', screeningInput($starts))
        ->set('ends_at', screeningInput($ends))
        ->call('save')
        ->assertHasNoErrors();

    expect(Screening::count())->toBe(2);
})->with([
    // 既存は 13:00〜15:00。
    '後の回（15:30開始）' => ['+15 hours +30 minutes', '+17 hours'],
    '前の回（12:30終了）' => ['+11 hours', '+12 hours +30 minutes'],
]);

it('編集時は自分自身をインターバル判定の対象にしない（4.8.3-4）', function () {
    $fixture = makeScreeningFixture();

    $screening = Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(13),
        'ends_at' => now()->startOfDay()->addHours(15),
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('editScreening', $screening->id)
        ->set('starts_at', screeningInput('+13 hours +30 minutes'))
        ->set('ends_at', screeningInput('+15 hours +30 minutes'))
        ->call('save')
        ->assertHasNoErrors();

    expect($screening->refresh()->starts_at->format('H:i'))->toBe('13:30');
});

it('cinema-admin は自館の上映回を登録できる（4.8.2）', function () {
    $fixture = makeScreeningFixture();
    $admin = createAdmin(AdminRole::CinemaAdmin, $fixture['cinema']);

    Livewire::actingAs($admin, 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $fixture['booking']->id)
        ->set('theater_id', (string) $fixture['theater']->id)
        ->set('starts_at', screeningInput('+10 hours'))
        ->set('ends_at', screeningInput('+12 hours'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Screening::sole()->created_by_admin_id)->toBe($admin->id);
});

it('cinema-admin は他館の上映回を一覧で見ることも編集することもできない（T-02）', function () {
    $own = makeScreeningFixture();
    $other = makeScreeningFixture(theaterNumber: 2);

    $foreign = Screening::create([
        'booking_id' => $other['booking']->id,
        'theater_id' => $other['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(10),
        'ends_at' => now()->startOfDay()->addHours(12),
    ]);

    $component = Livewire::actingAs(createAdmin(AdminRole::CinemaAdmin, $own['cinema']), 'admin')
        ->test(Index::class)
        ->assertDontSee($other['cinema']->name);

    // 親（Booking・Theater）が CinemaScope 適用済みのため、IDを直接渡しても引けない。
    expect(fn () => $component->call('editScreening', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

it('cinema-admin は他館の上映編成を指定して登録できない（T-02 / 17.2.1）', function () {
    $own = makeScreeningFixture();
    $other = makeScreeningFixture(theaterNumber: 2);

    Livewire::actingAs(createAdmin(AdminRole::CinemaAdmin, $own['cinema']), 'admin')
        ->test(Index::class)
        ->call('createScreening')
        ->set('booking_id', (string) $other['booking']->id)
        ->set('theater_id', (string) $other['theater']->id)
        ->set('starts_at', screeningInput('+10 hours'))
        ->set('ends_at', screeningInput('+12 hours'))
        ->call('save')
        ->assertHasErrors('booking_id');

    expect(Screening::count())->toBe(0);
});

it('gate ロールは一覧を取得できない（4.8.2 / T-12）', function () {
    $fixture = makeScreeningFixture();
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate, $fixture['cinema']), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('予約が存在する上映回は編集できない（4.8.6追記表）', function () {
    $fixture = makeScreeningFixture();

    $screening = Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->startOfDay()->addHours(10),
        'ends_at' => now()->startOfDay()->addHours(12),
    ]);
    makePaidReservation($screening);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('editScreening', $screening->id)
        ->assertSet('showForm', false);
});

it('予約が存在する上映回は削除できない（6.2 制約1）', function () {
    $fixture = makeScreeningFixture();

    $screening = Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);
    makePaidReservation($screening);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('deleteScreening', $screening->id);

    expect(Screening::whereKey($screening->id)->exists())->toBeTrue();
});

it('有効期限内の座席ロックがある上映回は削除できない（6.4.2 / 4.8.6追記表）', function () {
    $fixture = makeScreeningFixture();

    $screening = Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);

    $seatType = SeatType::create([
        'name' => '一般',
        'surcharge' => 0,
        'display_class' => SeatDisplayClass::Standard,
    ]);
    $seat = Seat::create([
        'theater_id' => $fixture['theater']->id,
        'seat_type_id' => $seatType->id,
        'row_label' => 'A',
        'seat_number' => '01',
        'grid_row' => 1,
        'grid_col' => 1,
    ]);
    SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seat->id,
        'holder_key' => 'session:test',
        'expires_at' => now()->addMinutes(10),
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('deleteScreening', $screening->id);

    expect(Screening::whereKey($screening->id)->exists())->toBeTrue();
});

it('開始前で予約もロックも無い上映回は削除できる（6.2 制約1）', function () {
    $fixture = makeScreeningFixture();

    $screening = Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('deleteScreening', $screening->id);

    expect(Screening::whereKey($screening->id)->exists())->toBeFalse();
});

it('開始済みの上映回は予約が無くても削除できない（6.2 上映実績の保持）', function () {
    $fixture = makeScreeningFixture();

    $screening = Screening::create([
        'booking_id' => $fixture['booking']->id,
        'theater_id' => $fixture['theater']->id,
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHour(),
    ]);

    Livewire::actingAs(createAdmin(), 'admin')
        ->test(Index::class)
        ->call('deleteScreening', $screening->id);

    expect(Screening::whereKey($screening->id)->exists())->toBeTrue();
});
