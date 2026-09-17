<?php

use App\Enums\AdminRole;
use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Enums\SeatDisplayClass;
use App\Models\Admin;
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
use App\Services\ReservationDraft;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
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
 * tests/Concurrency は複数のコネクション（子プロセス）から同一のデータを操作するため、
 * テストをトランザクションで包む RefreshDatabase を使えない（13.6「実行環境」）。
 * 用意したデータを子プロセスから見えるようにするため DatabaseTruncation を用いる。
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
 * DatabaseTruncation はデータをコミットして残すため、後続の Feature テスト
 * （RefreshDatabase）のうち空のデータベースを前提にしているものが実行順によって落ちる。
 * 落ちるのは無関係なテストであり原因の切り分けに時間を要するため、ディレクトリ全体に
 * 後始末を掛ける（テストファイル単位にすると、追加時の書き漏れで再発する）。
 */
pest()->afterEach(function () {
    $this->truncateTablesForAllConnections();
})->in('Concurrency');

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
 * テスト用の館を1件作成する。
 * slug を省略した場合は 4.1.3-6 の形式（半角英小文字とハイフンのみ）で採番する。
 */
function createCinema(?string $slug = null, string $name = 'テスト館'): Cinema
{
    return Cinema::create([
        'slug' => $slug ?? 'test-cinema-'.Str::lower(Str::password(8, letters: true, numbers: false, symbols: false)),
        'name' => $name,
        'concept' => 'テスト用の館',
        'address' => '京都府京都市',
        'phone' => '123-4567-8900',
        'business_hours' => '8:00〜23:55',
        'facility_info' => 'テスト',
        'access_note' => 'テスト',
        'map_embed_url' => 'https://www.google.com/maps?q=test&output=embed',
    ]);
}

/**
 * A-03（館マスタ管理）のLivewireフォームに投入する、バリデーションを通過する
 * 入力値一式を返す。`$overrides` で一部の項目だけ差し替えて検証できる。
 *
 * @return array<string, string>
 */
function validCinemaForm(array $overrides = []): array
{
    return array_merge([
        'slug' => 'shijo-karasuma',
        'name' => 'ムビ四条烏丸',
        'concept' => '新しい館',
        'address' => '京都府京都市下京区',
        'phone' => '075-000-0000',
        'business_hours' => '9:00〜24:00',
        'facility_info' => '売店あり',
        'access_note' => '駅から徒歩5分',
        'map_embed_url' => 'https://www.google.com/maps?q=shijo-karasuma&output=embed',
    ], $overrides);
}

/**
 * A-14（管理者アカウント管理）のLivewireフォームに投入する入力値一式を返す。
 * `$overrides` で一部の項目だけ差し替えて検証できる。
 *
 * **既定値のままでは通過しない。** 既定の役割が `cinema-admin` であり、この役割は
 * 所属館が必須（4.8.6追記表）のため、呼び出し側で `cinema_id` を指定するか
 * `role` を `super-admin` へ差し替えること。
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function validAccountForm(array $overrides = []): array
{
    return array_merge([
        'login_id' => 'gion-admin',
        'name' => '祇園館 管理者',
        'role' => AdminRole::CinemaAdmin->value,
        'cinema_id' => '',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ], $overrides);
}

/**
 * パスワード変更（A-15）の入力欄を、検証を通過する値で埋めた配列を返す。
 * `current_password` は `createAdmin()` の既定パスワードに合わせている。
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function validPasswordChangeForm(array $overrides = []): array
{
    return array_merge([
        'current_password' => 'password',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ], $overrides);
}

/**
 * テスト用の管理者を1件作成する。パスワードは平文 'password'（Adminモデルの
 * casts()が'hashed'のため自動ハッシュ化される）。
 */
function createAdmin(AdminRole $role = AdminRole::SuperAdmin, ?Cinema $cinema = null): Admin
{
    return Admin::create([
        'login_id' => 'admin-'.Str::lower(Str::random(8)),
        'password' => 'password',
        'name' => 'テスト管理者',
        'role' => $role,
        'cinema_id' => $cinema?->id,
        'is_active' => true,
    ]);
}

/**
 * テスト用の館とシアターを1件作成する。
 */
function createTheater(): Theater
{
    $cinema = createCinema();

    return Theater::create([
        'cinema_id' => $cinema->id,
        'number' => 1,
        'name' => '1番シアター',
    ]);
}

/**
 * 座席の排他制御テスト用に、上映回と座席1件を最小構成で作成する。
 *
 * @return array{0: Screening, 1: Seat}
 */
function createScreeningWithSeat(): array
{
    $theater = createTheater();

    // 同一テスト内で複数回呼べるよう冪等にする（`m_seat_types.name` は一意）。
    $seatType = SeatType::firstOrCreate(
        ['name' => '一般'],
        ['surcharge' => 0, 'display_class' => SeatDisplayClass::Standard],
    );

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
 * 本日の上映回1件と、そこに紐づく座席・券種を用意する。
 *
 * @return array{screening: Screening, seat: Seat, ticketTypeId: int}
 */
function makeTodayScreening(): array
{
    [$screening, $seat] = createScreeningWithSeat();
    $screening->update(['starts_at' => now()->setTime(10, 0), 'ends_at' => now()->setTime(12, 0)]);

    return ['screening' => $screening, 'seat' => $seat, 'ticketTypeId' => createTicketType()->id];
}

/**
 * 予約を1件、座席つきで作成する。
 *
 * `$releaseSeats` は 6.4.2 の「予約が cancelled または expired に遷移した時点で
 * released_at を設定する」を再現するためのもの。4.4-2 によりキャンセルは予約単位
 * なので、実データでは status と released_at が必ず連動する。
 *
 * @param  array{screening: Screening, seat: Seat, ticketTypeId: int}  $ctx
 * @param  array<string, mixed>  $overrides
 */
function makeSeatedReservation(array $ctx, array $overrides = [], int $seatAmount = 2000, bool $releaseSeats = false): Reservation
{
    $reservation = Reservation::create(array_merge([
        'reservation_no' => nextTestReservationNo(),
        'user_id' => null,
        'guest_name' => '予約 太郎',
        'guest_name_kana' => 'ヨヤク タロウ',
        'contact_type' => ContactType::Guest,
        'guest_email' => 'guest@example.test',
        'guest_phone' => '09000000000',
        'screening_id' => $ctx['screening']->id,
        'status' => ReservationStatus::Paid,
        'total_amount' => 2000,
    ], $overrides));

    $reservationSeat = ReservationSeat::create([
        'reservation_id' => $reservation->id,
        'screening_id' => $ctx['screening']->id,
        'seat_id' => $ctx['seat']->id,
        'ticket_type_id' => $ctx['ticketTypeId'],
        'amount' => $seatAmount,
    ]);

    if ($releaseSeats) {
        // released_at は fillable に含めない（解放は予約のキャンセル処理が行う）。
        $reservationSeat->released_at = now();
        $reservationSeat->save();
    }

    return $reservation;
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
 * 券種を1件作成する（大人・2,000円）。価格を変えたい場合は `adultTicket()` を使う。
 */
function createTicketType(): TicketType
{
    return adultTicket();
}

/**
 * 名称と価格を指定して券種を作る（`m_ticket_types.name` は一意）。
 *
 * `firstOrCreate` にすると、既に同名の券種がある場合に `$price` が黙って無視され、
 * 金額の期待値の根拠が崩れる（「通るが検証していない」テストになる）。
 */
function makeTicketType(string $name, int $price, int $displayOrder = 1): TicketType
{
    return TicketType::updateOrCreate(['name' => $name], ['price' => $price, 'display_order' => $displayOrder]);
}

/** 大人券種（ペア割の対象、6.5.2）。 */
function adultTicket(int $price = 2000): TicketType
{
    return makeTicketType(TicketType::ADULT_NAME, $price);
}

/** 大人以外の券種（ペア割の対象外）。並び順はシーダー（6.5.1）に合わせて2とする。 */
function studentTicket(int $price = 1500): TicketType
{
    return makeTicketType('学生', $price, displayOrder: 2);
}

/**
 * 指定した追加料金の座席種別で座席を $count 席作成する（同一シアター内）。
 */
function makeSeatsWithSurcharge(Theater $theater, int $count, int $surcharge): SeatType
{
    $seatType = SeatType::create([
        'name' => "テスト種別-{$theater->id}-{$surcharge}",
        'surcharge' => $surcharge,
        'display_class' => SeatDisplayClass::Standard,
    ]);

    foreach (range(1, $count) as $i) {
        Seat::create([
            'theater_id' => $theater->id,
            'seat_type_id' => $seatType->id,
            'row_label' => 'ZZ',
            'seat_number' => str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'grid_row' => 999,
            'grid_col' => $i,
        ]);
    }

    return $seatType;
}

/**
 * 1つの上映編成（追加料金 $bookingSurcharge）のもとに、$startTimes の数だけ上映回を作成する。
 *
 * @param  array<int, CarbonImmutable>  $startTimes
 * @return array<int, Screening>
 */
function makeScreenings(Theater $theater, array $startTimes, int $bookingSurcharge = 0): array
{
    $format = Format::firstOrCreate(['name' => '2D'], ['default_surcharge' => 0]);

    $movie = Movie::create([
        'tmdb_id' => random_int(1, 999_999_999),
        'title' => 'テスト作品',
        'synopsis' => 'テスト用のあらすじ',
        'runtime_minutes' => 100,
        'released_on' => now()->subYears(2),
    ]);

    $booking = Booking::create([
        'cinema_id' => $theater->cinema_id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now()->subYears(2),
        'ends_on' => now()->addWeek(),
        'surcharge' => $bookingSurcharge,
    ]);

    return collect($startTimes)
        ->map(fn (CarbonImmutable $startsAt) => Screening::create([
            'booking_id' => $booking->id,
            'theater_id' => $theater->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(2),
        ]))
        ->all();
}

/**
 * 予約フロー（P-31 以降）のテスト用に、販売期間内の上映回と、その回のシアターに属する
 * 座席を作成する。座席は `grid_col` の昇順（座席表の描画順）で返す。
 *
 * @return array{screening: Screening, seats: array<int, Seat>, theater: Theater}
 */
function makeReservationFixture(int $seatCount = 3): array
{
    $theater = createTheater();
    $seatType = makeSeatsWithSurcharge($theater, $seatCount, 0);
    $seats = Seat::where('seat_type_id', $seatType->id)->orderBy('id')->get()->all();

    [$screening] = makeScreenings($theater, [CarbonImmutable::now()->addDay()->setTime(10, 0)]);

    return ['screening' => $screening, 'seats' => $seats, 'theater' => $theater];
}

/**
 * 座席を保持した状態（P-31 で選択済み）を作る。
 *
 * $holderKey を省略した場合は現在のテストプロセスの保持者キーを使う。**HTTPリクエストを
 * 伴うテストでは省略できない**（`$this->get()` はテストプロセスとは別のセッションIDを
 * 発行するため、`session:{id}` が一致しない）。会員として `user:{id}` を渡すこと。
 */
function holdSeatsForScreening(Screening $screening, ?string $holderKey, Seat ...$seats): void
{
    $locks = app(SeatLockService::class);
    $holderKey ??= $locks->holderKey();

    foreach ($seats as $seat) {
        // 取得に失敗したまま進むと「用意したはずの座席が無いのに通るテスト」になる。
        expect($locks->acquire($screening, $seat, $holderKey))
            ->toBeTrue("座席 {$seat->id} のロックを取得できませんでした。");
    }
}

/*
 * 利用規約への同意（P-32、4.3.7-7）を済ませた状態にする。P-33 以降は同意が無いと
 * 先へ進めない（4.3.12、旧12章 残課題25）ため、その段階を検証するテストの前提になる。
 */
function agreeToTerms(Screening $screening): void
{
    app(ReservationDraft::class)->agree($screening);
}

/*
 * HTTP テスト用。`$this->get()` はテストプロセスとは別のセッションで動くため、
 * `withSession()` へ渡す形で同意済みの状態を組み立てる。
 *
 * セッションの構造を直接書かず `ReservationDraft` に作らせる。構造が変わっても
 * テスト側の修正が要らないようにするため。
 *
 * **副作用**: 組み立てのために `agreeToTerms()` を呼ぶため、テストプロセス側の
 * セッションにも同意が書かれる。同一のテストで「未同意」を前提とする
 * `Livewire::test()` と併用しないこと。
 *
 * @return array<string, mixed>
 */
function agreedDraftSession(Screening $screening): array
{
    agreeToTerms($screening);

    return ['reservation' => session('reservation')];
}
