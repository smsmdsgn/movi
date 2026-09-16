<?php

use App\Livewire\Front\Reservation\CustomerInfo;
use App\Models\User;
use App\Services\ReservationDraft;
use App\Services\SeatLockService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * お客様情報の入力（P-34、7.9 / 4.3.6）。入力の検証・持ち越し先・到達の前提を固定する。
 * フリガナの文字集合そのものは tests/Feature/Rules/FullWidthKatakanaTest.php が担保する。
 */

/*
 * 正しく埋めた入力。各テストは検証したい1項目だけを差し替える。
 *
 * @return array<string, string>
 */
function validCustomerInput(): array
{
    return [
        'name' => '祇園　太郎',
        'nameKana' => 'ギオン　タロウ',
        'phone' => '09012345678',
        'email' => 'taro@example.com',
        'emailConfirmation' => 'taro@example.com',
    ];
}

/*
 * 先へ進める前提（座席の保持・利用規約への同意）を満たした上映回を用意する。
 *
 * @return array<string, mixed>
 */
function readyForCustomerInfo(): array
{
    $fixture = makeReservationFixture();
    holdSeatsForScreening($fixture['screening'], null, $fixture['seats'][0]);
    agreeToTerms($fixture['screening']);

    return $fixture;
}

it('入力フォームを表示する（7.9）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.customer.fields.name'))
        ->assertSee(__('front.reservation.customer.fields.nameKana'))
        ->assertSee(__('front.reservation.customer.fields.phone'))
        ->assertSee(__('front.reservation.customer.fields.email'))
        ->assertSee(__('front.reservation.customer.fields.emailConfirmation'))
        ->assertSee(__('front.reservation.customer.proceed'));
});

it('ページが上映情報を表示しクロール対象外とする（19.3-6）', function () {
    ['screening' => $screening, 'theater' => $theater] = makeReservationFixture();

    // 未ログイン・前提未充足でもページ（上映情報とメタ情報）は描画される。
    // 入力の出し分けは Livewire コンポーネント側のテストが担保する。
    $this->get(route('front.reservation.customer', ['id' => $screening->id]))
        ->assertOk()
        ->assertSee('テスト作品')
        ->assertSee($theater->name)
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('存在しない上映回は404を返す', function () {
    $this->get(route('front.reservation.customer', ['id' => 999_999]))->assertNotFound();
});

it('入力を確定すると券種選択（P-35）へ進み、入力値を持ち越す（7.18 / 4.3.12）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('front.reservation.tickets', ['id' => $screening->id]));

    // 予約は決済完了時にしか作られない（6.4.2）。それまでの持ち越し先はセッション。
    expect(app(ReservationDraft::class)->guest($screening->id))->toBe([
        'name' => '祇園　太郎',
        'name_kana' => 'ギオン　タロウ',
        'phone' => '09012345678',
        'email' => 'taro@example.com',
    ]);
});

it('すべての項目が必須である（7.9）', function (string $property) {
    ['screening' => $screening] = readyForCustomerInfo();

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set($property, '')
        ->call('submit')
        ->assertHasErrors([$property => 'required'])
        ->assertNoRedirect();
})->with(['name', 'nameKana', 'phone', 'email', 'emailConfirmation']);

it('氏名は全角文字に限る（4.3.6）', function (string $value, bool $valid) {
    ['screening' => $screening] = readyForCustomerInfo();

    $component = Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set('name', $value)
        ->call('submit');

    $valid
        ? $component->assertHasNoErrors('name')
        : $component->assertHasErrors('name');
})->with([
    '漢字' => ['祇園太郎', true],
    '全角スペース区切り' => ['祇園　太郎', true],
    'ひらがな' => ['ぎおんたろう', true],
    '半角英字' => ['Taro', false],
    '半角スペースの混在' => ['祇園 太郎', false],
    '半角カタカナ' => ['ｷﾞｵﾝ', false],
    '半角数字の混在' => ['祇園太郎2', false],
]);

it('フリガナは全角カタカナに限り、A-11 の検索側と同じ文字集合を使う（旧12章 残課題19）', function (string $value, bool $valid) {
    ['screening' => $screening] = readyForCustomerInfo();

    $component = Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set('nameKana', $value)
        ->call('submit');

    $valid
        ? $component->assertHasNoErrors('nameKana')
        : $component->assertHasErrors('nameKana');
})->with([
    'カタカナ' => ['ギオンタロウ', true],
    '中黒' => ['ジョン・スミス', true],
    '長音符' => ['コーヒー', true],
    '漢字' => ['祇園太郎', false],
    'ひらがな' => ['ぎおんたろう', false],
    '半角カタカナ' => ['ｷﾞｵﾝ', false],
]);

it('電話番号はハイフンなしの半角数字10〜11桁に限る（4.3.6）', function (string $value, bool $valid) {
    ['screening' => $screening] = readyForCustomerInfo();

    $component = Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set('phone', $value)
        ->call('submit');

    $valid
        ? $component->assertHasNoErrors('phone')
        : $component->assertHasErrors('phone');
})->with([
    '携帯電話11桁' => ['09012345678', true],
    '固定電話10桁' => ['0751234567', true],
    'ハイフンあり' => ['090-1234-5678', false],
    '全角数字' => ['０９０１２３４５６７８', false],
    '9桁' => ['012345678', false],
    '12桁' => ['012345678901', false],
]);

it('メールアドレスは確認用の再入力と一致しなければならない（7.9）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set('emailConfirmation', 'other@example.com')
        ->call('submit')
        ->assertHasErrors(['emailConfirmation' => 'same'])
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.customer.errors.email_mismatch'));
});

it('形式が誤ったメールアドレスを拒否する（4.3.6）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set('email', 'not-an-email')
        ->set('emailConfirmation', 'not-an-email')
        ->call('submit')
        ->assertHasErrors(['email' => 'email'])
        ->assertNoRedirect();
});

it('記録済みの入力を書き戻すが、確認用の再入力は書き戻さない（4.3.12）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->call('submit')
        ->assertHasNoErrors();

    // P-35 から戻った場合の再表示。控えが届かない事故を防ぐ項目のため、確認用は空に戻す。
    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->assertSet('name', '祇園　太郎')
        ->assertSet('nameKana', 'ギオン　タロウ')
        ->assertSet('phone', '09012345678')
        ->assertSet('email', 'taro@example.com')
        ->assertSet('emailConfirmation', '');
});

it('同意画面（P-32）を経ていない場合は入力を求めず、同意画面へ戻す導線を出す（4.3.12）', function () {
    ['screening' => $screening, 'seats' => $seats] = makeReservationFixture();
    holdSeatsForScreening($screening, null, $seats[0]);

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.agreement_required'))
        ->assertDontSee(__('front.reservation.customer.proceed'))
        // 復帰先は P-31 ではなく P-32。座席を選び直させない（4.3.12）。
        ->assertSee(route('front.reservation.agreement', ['id' => $screening->id]))
        ->set(validCustomerInput())
        ->call('submit')
        ->assertNoRedirect();

    expect(app(ReservationDraft::class)->guest($screening->id))->toBeNull();
});

it('座席を保持していない場合は入力を求めず、座席選択へ戻す導線を出す（4.3.12）', function () {
    ['screening' => $screening] = makeReservationFixture();
    agreeToTerms($screening);

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.lock_expired'))
        ->assertDontSee(__('front.reservation.customer.proceed'))
        ->assertSee(route('front.reservation.seats', ['id' => $screening->id]))
        ->set(validCustomerInput())
        ->call('submit')
        ->assertNoRedirect();
});

it('保持中の座席が期限切れになると入力を確定できない（6.4.1-3）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    $component = Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput());

    $this->travel(SeatLockService::LOCK_MINUTES + 1)->minutes();

    $component->call('submit')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.lock_expired'));

    expect(app(ReservationDraft::class)->guest($screening->id))->toBeNull();
});

it('販売期間外の上映回では入力を求めず、復帰先も出さない（4.3.1 / 4.3.12）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    $screening->update([
        'starts_at' => CarbonImmutable::now()->subHour(),
        'ends_at' => CarbonImmutable::now()->addHour(),
    ]);

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->assertSee(__('front.reservation.errors.out_of_sale'))
        ->assertDontSee(__('front.reservation.customer.proceed'))
        // 座席を選び直してもこの回は買えないため、往復させる導線を残さない（4.3.12）。
        ->assertDontSee(__('front.reservation.back_to_seats'))
        ->call('submit')
        ->assertNoRedirect();
});

it('上映回が削除されても 7.17 の文言を返し、例外にしない（4.3.10）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    $component = Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput());

    $screening->delete();

    $component->call('submit')
        ->assertNoRedirect()
        ->assertSee(__('front.reservation.errors.out_of_sale'));
});

it('ログイン済みの会員には入力を求めず券種選択へ送る（4.3.11）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForCustomerInfo();
    $user = User::factory()->create();
    holdSeatsForScreening($screening, 'user:'.$user->id, $seats[1]);

    // 会員は P-33 から P-35 へ直行する。P-34 は非会員だけが通る画面（7.8）。
    Livewire::actingAs($user)
        ->test(CustomerInfo::class, ['screening' => $screening])
        ->assertRedirect(route('front.reservation.tickets', ['id' => $screening->id]));

    // Livewire::test() は mount() の redirect を直接観測するだけで、フルページの経路
    // （初回描画の dehydrate による abort(redirect())）を通らないため、実経路も固定する。
    $this->actingAs($user)
        ->withSession(agreedDraftSession($screening))
        ->get(route('front.reservation.customer', ['id' => $screening->id]))
        ->assertRedirect(route('front.reservation.tickets', ['id' => $screening->id]));
});

it('別のタブでログインした後に送信しても、会員の予約に非会員の入力を残さない（4.3.12）', function () {
    ['screening' => $screening, 'seats' => $seats] = readyForCustomerInfo();
    $user = User::factory()->create();

    // 未ログインで P-34 を開き、入力を終えた状態。
    $component = Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput());

    // 別のタブでログインを済ませた（座席ロックは `user:{id}` へ移譲されている）。
    holdSeatsForScreening($screening, 'user:'.$user->id, $seats[1]);
    $this->actingAs($user);

    $component->call('submit')
        ->assertRedirect(route('front.reservation.tickets', ['id' => $screening->id]));

    expect(app(ReservationDraft::class)->guest($screening->id))->toBeNull();
});

it('氏名・フリガナの前後の空白を落としてから記録する（4.3.12 / 4.8.5）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    // A-11 のフリガナ検索は前方一致のため、先頭に空白が入ると窓口から引き当てられない。
    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set('name', '　祇園　太郎　')
        ->set('nameKana', '　ギオン　タロウ　')
        ->set('email', ' taro@example.com ')
        ->set('emailConfirmation', ' taro@example.com ')
        ->call('submit')
        ->assertHasNoErrors();

    // 語中の空白（姓名の区切り）は残す。
    expect(app(ReservationDraft::class)->guest($screening->id))->toBe([
        'name' => '祇園　太郎',
        'name_kana' => 'ギオン　タロウ',
        'phone' => '09012345678',
        'email' => 'taro@example.com',
    ]);
});

it('入力に誤りがあると、件数の要約をライブリージョンへ出す（13.5-5）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set('name', 'Taro')
        ->set('phone', '090-1234-5678')
        ->call('submit')
        ->assertHasErrors(['name', 'phone'])
        ->assertSee(__('front.reservation.customer.errors.summary', ['count' => 2]))
        ->assertSee(__('front.reservation.customer.errors.jump_to_first'));
});

it('顧客向けの語調で文言を出す（7.17 / 4.3.12）', function () {
    ['screening' => $screening] = readyForCustomerInfo();

    // 管理画面（A-11）の「入力してください」ではなく「ご入力ください」を使う。
    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->set('nameKana', 'ぎおんたろう')
        ->call('submit')
        ->assertSee(__('front.reservation.customer.errors.name_kana'))
        ->assertDontSee(__('admin.reservation_search.errors.kana_only'));
});

it('別の上映回には入力を引き継がない（4.3.12）', function () {
    ['screening' => $screening, 'theater' => $theater] = readyForCustomerInfo();

    Livewire::test(CustomerInfo::class, ['screening' => $screening])
        ->set(validCustomerInput())
        ->call('submit')
        ->assertHasNoErrors();

    $other = createScreeningForTheater($theater);

    expect(app(ReservationDraft::class)->guest($other->id))->toBeNull();
});
