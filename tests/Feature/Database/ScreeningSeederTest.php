<?php

use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\Theater;
use Database\Seeders\GionSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\MovieSeeder;
use Database\Seeders\ScreeningSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * ScreeningSeeder は45シアター×過去2年〜未来2週間のフル規模で実装しているため
 * (docs/design.md 9.2)、DatabaseSeeder 経由のテストは重い。ここでは最小限の
 * フィクスチャ（`createTheater()`、tests/Pest.php）に対して直接実行し、
 * スケジューリングの正しさを検証する。
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);
});

/**
 * @param  array{tmdb_id: int, title: string, runtime_minutes: int, released_on: string}  $movie
 * @param  array<int, string>  $formatNames
 */
function attachTestMovie(array $movie, array $formatNames = ['2D']): Movie
{
    $created = Movie::firstOrCreate(
        ['tmdb_id' => $movie['tmdb_id']],
        [
            'title' => $movie['title'],
            'synopsis' => 'テスト作品',
            'runtime_minutes' => $movie['runtime_minutes'],
            'released_on' => $movie['released_on'],
            'genres' => ['ドラマ'],
        ]
    );
    $created->formats()->sync(Format::whereIn('name', $formatNames)->pluck('id'));

    return $created;
}

test('screenings respect the daily operating window, trailer time, and interval', function () {
    $theater = createTheater();
    $theater->formats()->sync(Format::whereIn('name', ['2D', 'MOVI GRAND'])->pluck('id'));
    attachTestMovie(['tmdb_id' => 990001, 'title' => 'テスト映画A', 'runtime_minutes' => 120, 'released_on' => '2024-01-01']);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $screenings = Screening::where('theater_id', $theater->id)->orderBy('starts_at')->get();

    expect($screenings)->not->toBeEmpty();

    $firstDay = $screenings->first()->starts_at->toDateString();
    $screeningsOnFirstDay = $screenings->filter(fn ($s) => $s->starts_at->toDateString() === $firstDay)->values();

    // 1日の最初の上映は10:00開始
    expect($screeningsOnFirstDay->first()->starts_at->format('H:i'))->toBe('10:00');

    // 終了時刻 = 開始 + 上映時間(120分) + 予告編15分
    $firstScreening = $screeningsOnFirstDay->first();
    expect((int) $firstScreening->starts_at->diffInMinutes($firstScreening->ends_at))->toBe(135);

    // 同一シアターの上映回どうしは、前の回の終了から30分以上空ける
    for ($i = 1; $i < $screeningsOnFirstDay->count(); $i++) {
        $gap = (int) $screeningsOnFirstDay[$i - 1]->ends_at->diffInMinutes($screeningsOnFirstDay[$i]->starts_at);
        expect($gap)->toBeGreaterThanOrEqual(30);
    }

    // どの上映回も日をまたがず、23:55を超えて終わらない
    foreach ($screenings as $screening) {
        expect($screening->ends_at->isSameDay($screening->starts_at))->toBeTrue();
        expect($screening->ends_at->format('H:i') <= '23:55')->toBeTrue();
    }
});

test('the booking format is the highest-surcharge format both the theater and movie support', function () {
    $theater = createTheater();
    $theater->formats()->sync(Format::whereIn('name', ['2D', 'MOVI GRAND'])->pluck('id'));
    attachTestMovie(['tmdb_id' => 990002, 'title' => 'テスト映画B', 'runtime_minutes' => 100, 'released_on' => '2024-01-01'], ['2D', 'MOVI GRAND']);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $booking = Booking::with('format')->whereHas('screenings', fn ($q) => $q->where('theater_id', $theater->id))->firstOrFail();

    // シアターは 2D / MOVI GRAND に対応、作品も両方に対応するため、
    // 追加料金の高い MOVI GRAND（800円）が選ばれる
    expect($booking->format->name)->toBe('MOVI GRAND');
    expect($booking->surcharge)->toBe(800);
});

test('booking date ranges are inclusive and adjacent bookings do not overlap', function () {
    $theater = createTheater();
    $theater->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    attachTestMovie(['tmdb_id' => 990003, 'title' => 'テスト映画C', 'runtime_minutes' => 95, 'released_on' => '2024-01-01']);
    attachTestMovie(['tmdb_id' => 990004, 'title' => 'テスト映画D', 'runtime_minutes' => 110, 'released_on' => '2024-06-01']);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $bookings = Booking::where('cinema_id', $theater->cinema_id)->orderBy('starts_on')->get();

    expect($bookings->count())->toBeGreaterThan(1);

    // 上映回は自身の上映編成の期間内（starts_on〜ends_on、両端含む）に収まる
    $screenings = Screening::where('theater_id', $theater->id)->with('booking')->get();
    foreach ($screenings as $screening) {
        $day = $screening->starts_at->toDateString();
        expect($day)->toBeGreaterThanOrEqual($screening->booking->starts_on->toDateString());
        expect($day)->toBeLessThanOrEqual($screening->booking->ends_on->toDateString());
    }

    // 隣接する上映編成どうしは重複しない（前のends_on翌日が次のstarts_on）
    for ($i = 1; $i < $bookings->count(); $i++) {
        $expectedNextStart = $bookings[$i - 1]->ends_on->addDay()->toDateString();
        expect($bookings[$i]->starts_on->toDateString())->toBe($expectedNextStart);
    }
});

test('no booking uses a movie before its release date, even when an older movie is already eligible', function () {
    $theater = createTheater();
    $theater->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    // 生成開始時点で既に公開済みの旧作（$eligible フィルタが素通りする）と、
    // 生成期間の途中（1年前。生成開始は約2年前のため期間の後半で公開される）で
    // 公開される新作を用意する。新作は公開後に十分な数の上映編成サイクル
    // （1年分、約26回）が残るため決定的な選択にも必ず含まれる
    attachTestMovie(['tmdb_id' => 990005, 'title' => 'テスト映画E', 'runtime_minutes' => 100, 'released_on' => now()->subYears(3)->toDateString()]);
    attachTestMovie(['tmdb_id' => 990006, 'title' => 'テスト映画F', 'runtime_minutes' => 100, 'released_on' => now()->subYear()->toDateString()]);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $bookings = Booking::with('movie')->where('cinema_id', $theater->cinema_id)->get();

    expect($bookings)->not->toBeEmpty();
    foreach ($bookings as $booking) {
        expect($booking->starts_on->toDateString())->toBeGreaterThanOrEqual($booking->movie->released_on->toDateString());
    }

    // 新作（1年前公開。生成開始より後）が実際にいずれかの上映編成で使われていること
    $newMovie = Movie::where('tmdb_id', 990006)->firstOrFail();
    expect($bookings->pluck('movie_id')->contains($newMovie->id))->toBeTrue();
});

test('generation waits for the first release when no movie is eligible at the start', function () {
    $theater = createTheater();
    $theater->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    // 生成開始時点（約2年前）ではこの作品はまだ公開されておらず、$eligible が空になるため
    // nextReleaseAfter() が公開日までスキップする経路を通る
    attachTestMovie(['tmdb_id' => 990008, 'title' => 'テスト映画H', 'runtime_minutes' => 100, 'released_on' => now()->subYear()->toDateString()]);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $earliestBooking = Booking::where('cinema_id', $theater->cinema_id)->orderBy('starts_on')->first();

    expect($earliestBooking)->not->toBeNull();
    expect($earliestBooking->starts_on->toDateString())->toBe(now()->subYear()->toDateString());
});

test('sibling theaters in the same cinema are not scheduled identically', function () {
    $theaterA = createTheater();
    $theaterA->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    $theaterB = Theater::create(['cinema_id' => $theaterA->cinema_id, 'number' => 2, 'name' => '2番シアター']);
    $theaterB->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));

    foreach (range(1, 6) as $i) {
        attachTestMovie(['tmdb_id' => 990100 + $i, 'title' => "テスト映画{$i}", 'runtime_minutes' => 100, 'released_on' => '2024-01-01']);
    }

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $moviesA = Booking::whereHas('screenings', fn ($q) => $q->where('theater_id', $theaterA->id))->pluck('movie_id');
    $moviesB = Booking::whereHas('screenings', fn ($q) => $q->where('theater_id', $theaterB->id))->pluck('movie_id');

    expect($moviesA->toArray())->not->toBe($moviesB->toArray());
});

test('sibling theaters share one booking when they run the same movie, format and period', function () {
    // 4.8.3 は上映編成を館単位（t_bookings に theater_id が無い）と定めるため、
    // 同一館・同一作品・同一規格・同一期間の編成が複数行に割れてはならない。
    $theaterA = createTheater();
    $theaterA->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    $theaterB = Theater::create(['cinema_id' => $theaterA->cinema_id, 'number' => 2, 'name' => '2番シアター']);
    $theaterB->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));

    // 作品が1本しか無いため、両シアターは常に同じ作品・規格・期間を選ぶ。
    attachTestMovie(['tmdb_id' => 990009, 'title' => 'テスト映画I', 'runtime_minutes' => 100, 'released_on' => '2024-01-01']);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $bookings = Booking::where('cinema_id', $theaterA->cinema_id)->get();
    $uniqueKeys = $bookings->map(fn (Booking $booking) => implode('-', [
        $booking->movie_id,
        $booking->format_id,
        $booking->starts_on->toDateString(),
        $booking->ends_on->toDateString(),
    ]))->unique();

    expect($bookings)->not->toBeEmpty();
    expect($bookings->count())->toBe($uniqueKeys->count());

    // 編成1行に対して、両シアターの上映回がぶら下がっていること。館全体で数えると
    // シアターごとに編成を作る実装でも通ってしまうため、1行に限定して数える。
    $sharedTheaterIds = Screening::where('booking_id', $bookings->first()->id)
        ->distinct()
        ->pluck('theater_id')
        ->sort()
        ->values();

    expect($sharedTheaterIds->all())->toBe(collect([$theaterA->id, $theaterB->id])->sort()->values()->all());
});

test('re-seeding after adding a sibling theater reuses the existing bookings', function () {
    // 増分実行（既にシード済みの館へシアターを追加して再実行）で、上映編成が
    // 増えず、新シアターの上映回が既存の編成にぶら下がることを固定する。
    $theaterA = createTheater();
    $theaterA->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    attachTestMovie(['tmdb_id' => 990010, 'title' => 'テスト映画J', 'runtime_minutes' => 100, 'released_on' => '2024-01-01']);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $bookingIds = Booking::where('cinema_id', $theaterA->cinema_id)->pluck('id');

    $theaterB = Theater::create(['cinema_id' => $theaterA->cinema_id, 'number' => 2, 'name' => '2番シアター']);
    $theaterB->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    expect(Booking::where('cinema_id', $theaterA->cinema_id)->pluck('id')->all())->toBe($bookingIds->all());

    $newScreeningBookingIds = Screening::where('theater_id', $theaterB->id)->distinct()->pluck('booking_id');

    expect($newScreeningBookingIds)->not->toBeEmpty();
    expect($newScreeningBookingIds->diff($bookingIds)->all())->toBe([]);
});

test('every screening belongs to a theater in the same cinema as its booking', function () {
    // 上映回のシアターと編成の館の一致は外部キーで表現できない
    // （t_screenings.theater_id → m_theaters、t_bookings.cinema_id → m_cinemas）。
    // 1編成に複数シアターがぶら下がる形になったため、不変条件として固定する。
    $theaterA = createTheater();
    $theaterA->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    $theaterB = createTheater();
    $theaterB->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    attachTestMovie(['tmdb_id' => 990011, 'title' => 'テスト映画K', 'runtime_minutes' => 100, 'released_on' => '2024-01-01']);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $mismatched = Screening::with(['booking', 'theater'])
        ->get()
        ->filter(fn (Screening $screening) => $screening->theater->cinema_id !== $screening->booking->cinema_id);

    expect(Screening::count())->toBeGreaterThan(0);
    expect($mismatched)->toBeEmpty();
});

test('re-seeding a theater that already has screenings does not duplicate them', function () {
    $theater = createTheater();
    $theater->formats()->sync(Format::whereIn('name', ['2D'])->pluck('id'));
    attachTestMovie(['tmdb_id' => 990006, 'title' => 'テスト映画F', 'runtime_minutes' => 100, 'released_on' => '2024-01-01']);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);
    $firstCount = Screening::where('theater_id', $theater->id)->count();

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);
    $secondCount = Screening::where('theater_id', $theater->id)->count();

    expect($secondCount)->toBe($firstCount);
});

test('a theater with no compatible movies gets no bookings or screenings', function () {
    $theater = createTheater();
    attachTestMovie(['tmdb_id' => 990007, 'title' => 'テスト映画G', 'runtime_minutes' => 100, 'released_on' => '2024-01-01'], ['2D']);

    // シアターは MOVI MOTION のみに対応するが、作品は 2D にしか対応しないため
    // 規格の積集合が空になり、上映編成・上映回のいずれも作られないはず
    $theater->formats()->sync(Format::where('name', 'MOVI MOTION')->pluck('id'));

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    expect(Screening::where('theater_id', $theater->id)->exists())->toBeFalse();
    expect(Booking::where('cinema_id', $theater->cinema_id)->exists())->toBeFalse();
});

test('gion theaters also get screenings, since 9.1 handles it the same as the other 6 cinemas here', function () {
    Artisan::call('db:seed', ['--class' => GionSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => MovieSeeder::class, '--force' => true]);

    Artisan::call('db:seed', ['--class' => ScreeningSeeder::class, '--force' => true]);

    $gionTheaterIds = Cinema::where('slug', 'gion')->firstOrFail()->theaters()->pluck('id');

    expect(Theater::whereIn('id', $gionTheaterIds)->doesntHave('screenings')->exists())->toBeFalse();
});
