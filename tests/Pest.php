<?php

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\FreeTicket;
use App\Models\Movie;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatType;
use App\Models\Theater;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * 座席の排他制御テスト用に、上映回と座席1件を最小構成で作成する。
 *
 * @return array{0: Screening, 1: Seat}
 */
function createScreeningWithSeat(): array
{
    $cinema = Cinema::create([
        'slug' => 'test-cinema-'.Str::random(8),
        'name' => 'テスト館',
        'concept' => 'テスト用の館',
        'address' => '京都府京都市',
        'phone' => '123-4567-8900',
        'business_hours' => '8:00〜23:55',
        'facility_info' => 'テスト',
        'access_note' => 'テスト',
        'map_embed_url' => 'https://example.com/map',
    ]);

    $theater = Theater::create([
        'cinema_id' => $cinema->id,
        'number' => 1,
        'name' => '1番シアター',
    ]);

    $seatType = SeatType::create([
        'name' => '一般',
        'surcharge' => 0,
        'display_class' => 'standard',
    ]);

    $seat = Seat::create([
        'theater_id' => $theater->id,
        'seat_type_id' => $seatType->id,
        'row_label' => 'A',
        'seat_number' => '01',
        'grid_row' => 1,
        'grid_col' => 1,
    ]);

    $screening = createScreeningForTheater($theater);

    return [$screening, $seat];
}

/**
 * 既存のシアターに、別の作品・上映編成による上映回をもう1件作成する。
 * 「同一シアターの別上映回」を検証するテスト用。
 */
function createScreeningForTheater(Theater $theater): Screening
{
    $format = Format::firstOrCreate(['name' => '2D'], ['default_surcharge' => 0]);

    $movie = Movie::create([
        'tmdb_id' => random_int(1, 999_999_999),
        'title' => 'テスト作品',
        'synopsis' => 'テスト用のあらすじ',
        'runtime_minutes' => 120,
        'released_on' => now()->subYear(),
    ]);

    $booking = Booking::create([
        'cinema_id' => $theater->cinema_id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now(),
        'ends_on' => now()->addWeek(),
        'surcharge' => 0,
    ]);

    return Screening::create([
        'booking_id' => $booking->id,
        'theater_id' => $theater->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);
}

/**
 * テスト用の予約番号を1件採番する（8桁の数字、呼び出しごとに一意）。
 */
function nextTestReservationNo(): string
{
    static $sequence = 0;
    $sequence++;

    return str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);
}

/**
 * 決済済み（paid）の予約を1件作成する。
 */
function createPaidReservation(int $screeningId): Reservation
{
    return Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'user_id' => User::factory()->create()->id,
        'contact_type' => ContactType::Member,
        'screening_id' => $screeningId,
        'status' => ReservationStatus::Paid,
        'total_amount' => 2000,
    ]);
}

/**
 * 予約座席を1件作成する（券種は呼び出し側で用意する）。
 */
function createReservationSeat(int $screeningId, int $seatId, int $ticketTypeId): ReservationSeat
{
    return ReservationSeat::create([
        'reservation_id' => createPaidReservation($screeningId)->id,
        'screening_id' => $screeningId,
        'seat_id' => $seatId,
        'ticket_type_id' => $ticketTypeId,
        'amount' => 2000,
    ]);
}

/**
 * 指定した無料鑑賞券を使用する予約を1件作成する。
 */
function createReservationUsingFreeTicket(int $screeningId, int $freeTicketId, ReservationStatus $status): Reservation
{
    return Reservation::create([
        'reservation_no' => nextTestReservationNo(),
        'user_id' => User::factory()->create()->id,
        'contact_type' => ContactType::Member,
        'screening_id' => $screeningId,
        'status' => $status,
        'total_amount' => 0,
        'free_ticket_id' => $freeTicketId,
    ]);
}

/**
 * 会員1名に紐づく無料鑑賞券を1件作成する。
 */
function createFreeTicket(): FreeTicket
{
    return FreeTicket::create([
        'user_id' => User::factory()->create()->id,
        'code' => Str::upper(Str::random(12)),
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
    ]);
}

/**
 * 券種を1件作成する。
 */
function createTicketType(): TicketType
{
    return TicketType::create(['name' => '大人', 'price' => 2000, 'display_order' => 1]);
}
