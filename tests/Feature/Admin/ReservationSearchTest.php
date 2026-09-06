<?php

use App\Enums\AdminRole;
use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Livewire\Admin\ReservationSearch\Index;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * 検索対象の予約を1件、座席つきで作成する。
 * 共有ヘルパ `makeSeatedReservation()` に A-11 向けの既定値を与えるだけの薄い包み。
 *
 * @param  array{screening: Screening, seat: Seat, ticketTypeId: int}  $ctx
 * @param  array<string, mixed>  $overrides
 */
function makeSearchableReservation(array $ctx, array $overrides = []): Reservation
{
    return makeSeatedReservation($ctx, array_merge([
        'guest_name' => '検索 太郎',
        'guest_name_kana' => 'ケンサク タロウ',
        'guest_phone' => '09012345678',
    ], $overrides));
}

it('検索前は結果を表示しない', function () {
    $this->actingAs(createAdmin(), 'admin')
        ->get(route('admin.reservation.search'))
        ->assertOk()
        ->assertSee(__('admin.reservation_search.notices.before_search'));
});

it('予約番号で検索できる。ハイフンの有無を問わない（4.3.5）', function (string $input) {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['reservation_no' => '12345678']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', $input)
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee('1234-5678')
        ->assertSee('検索 太郎');
})->with([
    'ハイフンなし' => ['12345678'],
    'ハイフンあり' => ['1234-5678'],
]);

it('非会員のフリガナで前方一致検索できる（4.8.5）', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['guest_name_kana' => 'ケンサクタロウ']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'kana')
        ->set('term', 'ケンサク')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee('検索 太郎');
});

it('会員のフリガナでも検索できる（users 側を UNION で束ねている）', function () {
    $ctx = makeTodayScreening();
    $user = User::factory()->create(['name' => '会員 花子', 'name_kana' => 'カイイン ハナコ']);
    makeSearchableReservation($ctx, [
        'user_id' => $user->id,
        'contact_type' => ContactType::Member,
        'guest_name' => null,
        'guest_name_kana' => null,
        'guest_email' => null,
        'guest_phone' => null,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'kana')
        ->set('term', 'カイイン')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee('会員 花子');
});

it('フリガナは前方一致であり、中間一致では引かない（索引を効かせるため）', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['guest_name_kana' => 'ケンサクタロウ']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'kana')
        ->set('term', 'サクタロウ')
        ->call('search')
        ->assertSee(__('admin.reservation_search.notices.empty'));
});

it('電話番号で検索できる。会員・非会員の双方が対象になる', function (bool $asMember) {
    $ctx = makeTodayScreening();

    if ($asMember) {
        $user = User::factory()->create(['name' => '会員 花子', 'phone' => '08099998888']);
        makeSearchableReservation($ctx, [
            'user_id' => $user->id,
            'contact_type' => ContactType::Member,
            'guest_name' => null, 'guest_name_kana' => null,
            'guest_email' => null, 'guest_phone' => null,
        ]);
        $expected = '会員 花子';
    } else {
        makeSearchableReservation($ctx, ['guest_phone' => '08099998888']);
        $expected = '検索 太郎';
    }

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'phone')
        ->set('term', '08099998888')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee($expected);
})->with(['非会員' => [false], '会員' => [true]]);

it('検索結果にメールアドレス・電話番号・金額を表示しない（4.8.5-3）', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, [
        'reservation_no' => '12345678',
        'total_amount' => 987654,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', '12345678')
        ->call('search')
        ->assertSee('検索 太郎')
        ->assertDontSee('guest@example.test')
        ->assertDontSee('09012345678')
        ->assertDontSee('987654');
});

it('入力形式が不正な場合はエラーになる', function (string $searchBy, string $term) {
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', $searchBy)
        ->set('term', $term)
        ->call('search')
        ->assertHasErrors('term');
})->with([
    'フリガナに漢字' => ['kana', '検索'],
    'フリガナが1文字' => ['kana', 'ケ'],
    '電話番号にハイフン' => ['phone', '090-1234-5678'],
    '予約番号が桁不足' => ['reservation_no', '1234'],
]);

it('cinema-admin は他館の予約を検索できない（4.8.5-4 / 17.2.1）', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['reservation_no' => '12345678']);
    $otherCinema = createCinema('other', 'ムビ他館');
    $this->actingAs(createAdmin(AdminRole::CinemaAdmin, $otherCinema), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', '12345678')
        ->call('search')
        ->assertDontSee('検索 太郎')
        ->assertSee(__('admin.reservation_search.notices.empty'));
});

it('cinema-admin は自館の予約を検索できる（4.8.5-4）', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['reservation_no' => '12345678']);
    $cinemaId = $ctx['screening']->booking->cinema_id;
    $admin = createAdmin(AdminRole::CinemaAdmin);
    $admin->cinema_id = $cinemaId;
    $admin->save();
    $this->actingAs($admin, 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', '12345678')
        ->call('search')
        ->assertSee('検索 太郎');
});

it('キャンセル済みの予約も検索結果に出る（窓口での照合）', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, [
        'reservation_no' => '12345678',
        'status' => ReservationStatus::Cancelled,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', '12345678')
        ->call('search')
        ->assertSee(__('admin.reservation_search.state.cancelled'));
});

it('pending と expired の予約は検索結果に出ない', function (ReservationStatus $status) {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['reservation_no' => '12345678', 'status' => $status]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', '12345678')
        ->call('search')
        ->assertSee(__('admin.reservation_search.notices.empty'));
})->with([
    'pending' => [ReservationStatus::Pending],
    'expired' => [ReservationStatus::Expired],
]);

it('会員予約でもメールアドレスを表示しない（4.8.5-3）', function () {
    $ctx = makeTodayScreening();
    $user = User::factory()->create(['name' => '会員 花子', 'email' => 'member@example.test']);
    makeSearchableReservation($ctx, [
        'reservation_no' => '12345678',
        'user_id' => $user->id,
        'contact_type' => ContactType::Member,
        'guest_name' => null, 'guest_name_kana' => null,
        'guest_email' => null, 'guest_phone' => null,
    ]);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', '12345678')
        ->call('search')
        ->assertSee('会員 花子')
        ->assertDontSee('member@example.test');
});

it('検証を経ない入力は検索に使われない（公開プロパティの改変対策）', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['guest_name_kana' => 'ケンサクタロウ']);
    $this->actingAs(createAdmin(), 'admin');

    // search() を通さずに入力欄だけを書き換えても、確定値が空なので検索は走らない。
    // 部分一致（LIKE '%…%'）に持ち込む経路を塞いでいる。
    Livewire::test(Index::class)
        ->set('searchBy', 'kana')
        ->set('term', '%ケンサク')
        ->assertSee(__('admin.reservation_search.notices.before_search'))
        ->assertDontSee('検索 太郎');
});

it('検索後に入力欄を書き換えても、結果は確定値のまま変わらない', function () {
    $ctx = makeTodayScreening();
    $other = makeTodayScreening();
    makeSearchableReservation($ctx, ['guest_name_kana' => 'ケンサクタロウ']);
    makeSearchableReservation($other, [
        'guest_name_kana' => 'ベツジンハナコ',
        'guest_name' => '別人 花子',
    ]);
    $this->actingAs(createAdmin(), 'admin');

    // search() を呼ばずに入力欄だけを別人へ書き換える。確定値を見ていない実装なら
    // ここで結果が入れ替わる（＝validate() を迂回した検索が成立している）。
    Livewire::test(Index::class)
        ->set('searchBy', 'kana')
        ->set('term', 'ケンサク')
        ->call('search')
        ->assertSee('検索 太郎')
        ->set('term', 'ベツジン')
        ->assertSee('検索 太郎')
        ->assertDontSee('別人 花子');
});

it('確定値は Locked のためクライアントから変更できない', function () {
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)->set('appliedSearchBy', 'kana');
})->throws(CannotUpdateLockedPropertyException::class);

it('前後の全角スペースは検索前に取り除かれる', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['guest_name_kana' => 'ケンサクタロウ']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'kana')
        ->set('term', '　ケンサク　')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSee('検索 太郎');
});

it('クリアすると検索前の状態へ戻る', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['reservation_no' => '12345678']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', '12345678')
        ->call('search')
        ->assertSee('検索 太郎')
        ->call('clear')
        ->assertSet('term', '')
        ->assertSee(__('admin.reservation_search.notices.before_search'));
});

it('検索方法を切り替えると入力と結果が破棄される', function () {
    $ctx = makeTodayScreening();
    makeSearchableReservation($ctx, ['reservation_no' => '12345678']);
    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'reservation_no')
        ->set('term', '12345678')
        ->call('search')
        ->assertSee('検索 太郎')
        ->set('searchBy', 'kana')
        ->assertSet('term', '')
        ->assertSee(__('admin.reservation_search.notices.before_search'));
});

it('他館に大量の該当があっても、自館の予約は上限に掛からず引ける（4.8.5-4）', function () {
    // 上限判定を館スコープの前に置くと、他館の件数だけで cinema-admin が
    // 「該当が多すぎます」となり自館の予約へ到達できなくなる。
    $mine = makeTodayScreening();
    $others = makeTodayScreening();

    // 6.4.2 のユニーク制約（screening_id, active_seat_id）があるため、
    // 同一上映回の同一座席には1件しか置けない。
    makeSearchableReservation($mine, ['guest_name_kana' => 'サトウイチロウ', 'guest_name' => '自館 一郎']);
    makeSearchableReservation($others, ['guest_name_kana' => 'サトウジロウ', 'guest_name' => '他館 次郎']);

    $admin = createAdmin(AdminRole::CinemaAdmin);
    $admin->cinema_id = $mine['screening']->booking->cinema_id;
    $admin->save();
    $this->actingAs($admin, 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'kana')
        ->set('term', 'サトウ')
        ->call('search')
        ->assertSee('自館 一郎')
        ->assertDontSee('他館 次郎');
});

it('走査上限を超えると結果を出さず絞り込みを促す', function () {
    $theater = createTheater();
    makeSeatsWithSurcharge($theater, 201, 0);
    $screening = createScreeningForTheater($theater);
    $ticketTypeId = createTicketType()->id;
    $seatIds = Seat::where('theater_id', $theater->id)->pluck('id');

    foreach ($seatIds as $seatId) {
        $reservation = Reservation::create([
            'reservation_no' => nextTestReservationNo(),
            'guest_name' => '上限 太郎',
            'guest_name_kana' => 'ジョウゲンタロウ',
            'contact_type' => ContactType::Guest,
            'guest_email' => 'guest@example.test',
            'guest_phone' => '09012345678',
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Paid,
            'total_amount' => 2000,
        ]);

        ReservationSeat::create([
            'reservation_id' => $reservation->id,
            'screening_id' => $screening->id,
            'seat_id' => $seatId,
            'ticket_type_id' => $ticketTypeId,
            'amount' => 2000,
        ]);
    }

    $this->actingAs(createAdmin(), 'admin');

    Livewire::test(Index::class)
        ->set('searchBy', 'kana')
        ->set('term', 'ジョウゲン')
        ->call('search')
        ->assertSee(__('admin.reservation_search.notices.too_many', ['limit' => 200]))
        ->assertDontSee('上限 太郎');
});

it('gate ロールは予約検索を実行できない（17.1.3）', function () {
    $this->withoutExceptionHandling();
    $this->actingAs(createAdmin(AdminRole::Gate), 'admin');

    Livewire::test(Index::class)->set('searchBy', 'reservation_no')->set('term', '12345678')->call('search');
})->throws(AuthorizationException::class);
