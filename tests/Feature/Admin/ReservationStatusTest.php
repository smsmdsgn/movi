<?php

use App\Enums\AdminRole;
use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Livewire\Admin\Reservations\Index;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('super-admin は本日の上映回の予約状況を閲覧できる（4.8.2）', function () {
    $ctx = makeTodayScreening();
    makeSeatedReservation($ctx);

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.reservation.index'))
        ->assertOk()
        ->assertSee('テスト作品')
        ->assertSee('1 / 1 席');
});

it('予約明細に 予約者名・座席・券種・入場状態 を表示する（4.8.5）', function () {
    $ctx = makeTodayScreening();
    makeSeatedReservation($ctx);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('showReservations', $ctx['screening']->id)
        ->assertSet('showDetail', true)
        ->assertSee('予約 太郎')
        ->assertSee($ctx['seat']->displayName())
        ->assertSee('大人')
        ->assertSee(__('admin.reservation.state.not_checked_in'));
});

it('予約明細にメールアドレス・電話番号・金額を表示しない（4.8.5）', function () {
    $ctx = makeTodayScreening();
    // 金額は、Fluxが出力するSVGの名前空間（.../2000/svg）等と衝突しない値にする。
    makeSeatedReservation($ctx, ['total_amount' => 987654], seatAmount: 987654);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('showReservations', $ctx['screening']->id)
        ->assertSee('予約 太郎')
        ->assertDontSee('guest@example.test')
        ->assertDontSee('09000000000')
        ->assertDontSee('987654');
});

it('会員の予約は会員名を表示し、メールアドレスは表示しない（4.8.5）', function () {
    $ctx = makeTodayScreening();
    $user = User::factory()->create(['name' => '会員 花子', 'email' => 'member@example.test']);
    makeSeatedReservation($ctx, [
        'user_id' => $user->id,
        'contact_type' => ContactType::Member,
        'guest_name' => null,
        'guest_name_kana' => null,
        'guest_email' => null,
        'guest_phone' => null,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('showReservations', $ctx['screening']->id)
        ->assertSee('会員 花子')
        ->assertDontSee('member@example.test');
});

it('入場済みの予約は入場済みとして表示される', function () {
    $ctx = makeTodayScreening();
    $reservation = makeSeatedReservation($ctx);
    // checked_in_at は入場処理（4.6、工程8）が設定する列で fillable に含めない。
    $reservation->checked_in_at = now();
    $reservation->save();
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('showReservations', $ctx['screening']->id)
        ->assertSee(__('admin.reservation.state.checked_in'));
});

it('pending と expired の予約は明細に表示しない（座席を持たない・解放済みのため）', function (ReservationStatus $status) {
    $ctx = makeTodayScreening();
    makeSeatedReservation($ctx, ['reservation_no' => '90000001', 'status' => $status]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('showReservations', $ctx['screening']->id)
        ->assertDontSee('9000-0001');
})->with([
    'pending' => [ReservationStatus::Pending],
    'expired' => [ReservationStatus::Expired],
]);

it('キャンセル済みの予約は座席・券種つきで明細に残る（6.4.2 で released_at が入っても欠落しない）', function () {
    $ctx = makeTodayScreening();
    makeSeatedReservation(
        $ctx,
        ['reservation_no' => '90000002', 'status' => ReservationStatus::Cancelled],
        releaseSeats: true,
    );
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('showReservations', $ctx['screening']->id)
        ->assertSee('9000-0002')
        ->assertSee(__('admin.reservation.state.cancelled'))
        // 窓口での照合に必要なため、解放済みでも座席・券種を落とさない（4.8.5）。
        ->assertSee($ctx['seat']->displayName())
        ->assertSee('大人');
});

it('キャンセル済みの座席は一覧の予約席数に数えない（6.4.2 の占有の定義）', function () {
    $ctx = makeTodayScreening();
    makeSeatedReservation(
        $ctx,
        ['status' => ReservationStatus::Cancelled],
        releaseSeats: true,
    );

    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.reservation.index'))
        ->assertOk()
        ->assertSee('0 / 1 席');
});

it('cinema-admin は他館の上映回を一覧に表示しない（4.8.2 / 17.2.1）', function () {
    $ctx = makeTodayScreening();
    makeSeatedReservation($ctx);
    $otherCinema = createCinema('other', 'ムビ他館');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $otherCinema), 'admin')
        ->get(route('admin.reservation.index'))
        ->assertOk()
        ->assertDontSee('テスト作品')
        ->assertSee(__('admin.reservation.notices.empty'));
});

it('cinema-admin は他館の上映回の予約明細を開けない（17.2.1）', function () {
    $this->withoutExceptionHandling();
    $ctx = makeTodayScreening();
    $otherCinema = createCinema('other', 'ムビ他館');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $otherCinema), 'admin');

    Livewire::test(Index::class)->call('showReservations', $ctx['screening']->id);
})->throws(ModelNotFoundException::class);

it('cinema-admin が館セレクタの値を改変しても他館は見えない（17.15 T-11）', function () {
    $ctx = makeTodayScreening();
    makeSeatedReservation($ctx);
    // actingAs の後は CinemaScope が効いて booking を引けなくなるため先に取る。
    $targetCinemaId = $ctx['screening']->booking->cinema_id;
    $otherCinema = createCinema('other', 'ムビ他館');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $otherCinema), 'admin');

    // targetCinemaId() は cinema-admin の selectedCinemaId を無視して自館へ固定する。
    Livewire::test(Index::class)
        ->set('selectedCinemaId', $targetCinemaId)
        ->assertDontSee('テスト作品')
        ->assertSee(__('admin.reservation.notices.empty'));
});

it('cinema-admin が他館のシアターIDで絞り込んでも0件になる（17.15 T-11）', function () {
    $ctx = makeTodayScreening();
    makeSeatedReservation($ctx);
    $otherCinema = createCinema('other', 'ムビ他館');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $otherCinema), 'admin');

    Livewire::test(Index::class)
        ->set('filterTheaterId', $ctx['screening']->theater_id)
        ->assertDontSee('テスト作品');
});

it('モーダルを閉じると明細を保持しない（表示範囲の最小化）', function () {
    $ctx = makeTodayScreening();
    makeSeatedReservation($ctx);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->call('showReservations', $ctx['screening']->id)
        ->assertSee('予約 太郎')
        // ESC・背景クリックは showDetail だけを false にする。
        ->set('showDetail', false)
        ->assertDontSee('予約 太郎');
});

it('cinema-admin には館セレクタが表示されない（4.8.1-1）', function () {
    $cinema = createCinema('gion', '祇園ムビ');

    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $cinema), 'admin')
        ->get(route('admin.reservation.index'))
        ->assertOk()
        ->assertDontSee(__('admin.common.all_cinemas'));
});

it('gate ロールは予約状況を取得できない（17.1.3）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class);
})->throws(AuthorizationException::class);
