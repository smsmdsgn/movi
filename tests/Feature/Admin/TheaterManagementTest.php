<?php

use App\Enums\AdminRole;
use App\Enums\SeatDisplayClass;
use App\Livewire\Admin\Theaters\Index;
use App\Models\Booking;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\SeatType;
use App\Models\Theater;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

it('super-admin は全館のシアターを閲覧でき、館セレクタが表示される（4.8.1-2）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '1番シアター']);
    Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '1番シアター']);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.theater.index'))
        ->assertOk()
        ->assertSee('祇園ムビ')
        ->assertSee('ムビ京都')
        ->assertSee(__('admin.theater.all_cinemas'));
});

it('super-admin は館セレクタで特定館に絞り込める（4.8.1-2）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '祇園1番']);
    Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '京都1番']);

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('selectedCinemaId', $gion->id)
        ->assertSee('祇園1番')
        ->assertDontSee('京都1番');
});

it('cinema-admin は自館のシアターのみ閲覧でき、館セレクタが表示されない（4.8.2）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    $kyoto = createCinema('kyoto', 'ムビ京都');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '祇園1番']);
    Theater::create(['cinema_id' => $kyoto->id, 'number' => 1, 'name' => '京都1番']);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin')
        ->get(route('admin.theater.index'))
        ->assertOk()
        ->assertSee('祇園1番')
        ->assertDontSee('京都1番')
        ->assertDontSee(__('admin.theater.all_cinemas'));
});

it('cinema-admin にはシアターの編集ボタンが表示されない（4.8.2）', function () {
    $gion = createCinema('gion', '祇園ムビ');
    Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '祇園1番']);

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin')
        ->get(route('admin.theater.index'))
        ->assertOk()
        ->assertDontSee(__('admin.theater.actions.edit'))
        ->assertSee(__('admin.theater.actions.manage_seats'));
});

it('super-admin はシアター基本情報を編集できる（4.8.2）', function () {
    $theater = createTheater();
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTheater', $theater->id)
        ->set('name', '改装後シアター')
        ->set('number', 2)
        ->call('saveTheater')
        ->assertHasNoErrors()
        ->assertSet('showEditForm', false);

    expect($theater->fresh()->name)->toBe('改装後シアター');
    expect($theater->fresh()->number)->toBe(2);
});

it('同一館内でシアター番号が重複する場合はバリデーションエラーになる', function () {
    $cinema = createCinema();
    $theater1 = Theater::create(['cinema_id' => $cinema->id, 'number' => 1, 'name' => '1番シアター']);
    Theater::create(['cinema_id' => $cinema->id, 'number' => 2, 'name' => '2番シアター']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTheater', $theater1->id)
        ->set('number', 2)
        ->call('saveTheater')
        ->assertHasErrors(['number']);
});

it('編集時は自分自身の既存番号を重複エラーにしない', function () {
    $theater = createTheater();
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('editTheater', $theater->id)
        ->set('name', '同じ番号のまま')
        ->call('saveTheater')
        ->assertHasNoErrors();
});

it('cinema-admin はシアター編集を実行できない（4.8.2）', function () {
    $this->withoutExceptionHandling();
    $gion = createCinema('gion', '祇園ムビ');
    $theater = Theater::create(['cinema_id' => $gion->id, 'number' => 1, 'name' => '祇園1番']);
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    Livewire::test(Index::class)->call('editTheater', $theater->id);
})->throws(AuthorizationException::class);

it('editTheater を経由せず saveTheater を直接呼んでも何も更新されない', function () {
    $this->withoutExceptionHandling();
    $theater = createTheater();
    $this->actingAs(createAdmin(), 'admin');

    // showForm系プロパティはwire:model.selfで二方向バインドするためLockedにできないが、
    // editingTheaterId（Locked）が既定値のままなら更新対象を特定できず何もしない。
    Livewire::test(Index::class)
        ->set('showEditForm', true)
        ->set('name', '書き換え')
        ->call('saveTheater')
        ->assertHasNoErrors();

    expect($theater->fresh()->name)->not->toBe('書き換え');
});

it('cinema-admin は自館の座席の有効／無効を切り替えられる（4.8.2）', function () {
    [$screening, $seat] = createScreeningWithSeat();
    $theater = $seat->theater;
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $theater->cinema), 'admin');

    Livewire::test(Index::class)
        ->call('manageSeats', $theater->id)
        ->call('toggleSeat', $seat->id);

    expect($seat->fresh()->is_available)->toBeFalse();
});

it('super-admin はどの館の座席も切り替えられる（4.8.2）', function () {
    [$screening, $seat] = createScreeningWithSeat();
    $theater = $seat->theater;
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('manageSeats', $theater->id)
        ->call('toggleSeat', $seat->id);

    expect($seat->fresh()->is_available)->toBeFalse();
});

it('cinema-admin は他館の座席IDを指定しても操作できない（4.8.2 / 17.2.1）', function () {
    $this->withoutExceptionHandling();
    [$screening, $otherSeat] = createScreeningWithSeat();
    $gion = createCinema('gion', '祇園ムビ');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $gion), 'admin');

    Livewire::test(Index::class)->call('toggleSeat', $otherSeat->id);
})->throws(ModelNotFoundException::class);

it('未来の上映回に有効な予約がある座席は使用不可にできない（6.2 制約2）', function () {
    [$screening, $seat] = createScreeningWithSeat();
    $ticketType = createTicketType();
    createReservationSeat($screening->id, $seat->id, $ticketType->id);
    $theater = $seat->theater;
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('manageSeats', $theater->id)
        ->call('toggleSeat', $seat->id);

    expect($seat->fresh()->is_available)->toBeTrue();
});

it('未期限切れの座席ロックがある座席は使用不可にできない（6.2 制約2）', function () {
    [$screening, $seat] = createScreeningWithSeat();
    SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seat->id,
        'holder_key' => 'test-holder',
        'expires_at' => now()->addMinutes(5),
    ]);
    $theater = $seat->theater;
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('manageSeats', $theater->id)
        ->call('toggleSeat', $seat->id);

    expect($seat->fresh()->is_available)->toBeTrue();
});

it('過去の上映回の予約は使用不可への切替を妨げない（6.2）', function () {
    $theater = createTheater();
    $seatType = SeatType::create(['name' => '一般', 'surcharge' => 0, 'display_class' => SeatDisplayClass::Standard]);
    $seat = Seat::create([
        'theater_id' => $theater->id,
        'seat_type_id' => $seatType->id,
        'row_label' => 'A',
        'seat_number' => '01',
        'grid_row' => 1,
        'grid_col' => 1,
    ]);

    $format = Format::firstOrCreate(['name' => '2D'], ['default_surcharge' => 0]);
    $movie = Movie::create([
        'tmdb_id' => random_int(1, 999_999_999),
        'title' => 'テスト作品',
        'synopsis' => 'テスト用のあらすじ',
        'runtime_minutes' => 120,
        'released_on' => now()->subYears(2),
    ]);
    $booking = Booking::create([
        'cinema_id' => $theater->cinema_id,
        'movie_id' => $movie->id,
        'format_id' => $format->id,
        'starts_on' => now()->subMonth(),
        'ends_on' => now()->subMonth()->addWeek(),
        'surcharge' => 0,
    ]);
    $pastScreening = Screening::create([
        'booking_id' => $booking->id,
        'theater_id' => $theater->id,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->subMonth()->addHours(2),
    ]);

    $ticketType = createTicketType();
    createReservationSeat($pastScreening->id, $seat->id, $ticketType->id);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('manageSeats', $theater->id)
        ->call('toggleSeat', $seat->id);

    expect($seat->fresh()->is_available)->toBeFalse();
});

it('使用不可の座席は予約の有無に関わらず再度有効化できる', function () {
    [$screening, $seat] = createScreeningWithSeat();
    $seat->update(['is_available' => false]);
    $theater = $seat->theater;
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('manageSeats', $theater->id)
        ->call('toggleSeat', $seat->id);

    expect($seat->fresh()->is_available)->toBeTrue();
});

it('gate ロールはシアター一覧を取得できない（4.8.2 / 17.1.3）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);

it('TheaterPolicy::toggleSeats は gate ロールを拒否する（4.8.2 / 17.1.3）', function () {
    // render()時点のviewAnyがgateロールを先に弾くため（上記テストで検証済み）、
    // manageSeats/toggleSeatのアクション自体が同じくgateを拒否することはPolicy単体で確認する。
    $theater = createTheater();
    $gate = createAdmin(AdminRole::Gate, $theater->cinema);

    expect(Gate::forUser($gate)->allows('toggleSeats', $theater))->toBeFalse();
});

it('TheaterPolicy::toggleSeats は super-admin・cinema-admin を許可する（4.8.2）', function () {
    $theater = createTheater();

    expect(Gate::forUser(createAdmin())->allows('toggleSeats', $theater))->toBeTrue();
    expect(Gate::forUser(createAdmin(AdminRole::CinemaAdmin, $theater->cinema))->allows('toggleSeats', $theater))->toBeTrue();
});
