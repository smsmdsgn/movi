<?php

use App\Enums\ContactCategory;
use App\Livewire\Front\Contact\Index;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * お問い合わせ（P-14・P-15、4.9.2。工程7-g）。ダミー実装のため、入力の検証と
 * 送信完了画面への遷移のみを固定する。メール送信・DB保存・ログ出力を一切行わないこと
 * （4.9.2 / 17.4.3）も検証する。
 */

/**
 * 正しく埋めた入力。各テストは検証したい1項目だけを差し替える。
 *
 * @return array<string, string>
 */
function validContactInput(): array
{
    return [
        'name' => '祇園　太郎',
        'email' => 'taro@example.com',
        'category' => ContactCategory::Payment->value,
        'body' => 'お問い合わせの本文です。',
    ];
}

it('P-14 がタイトル・見出し・ダミーの注記・採用情報へのリンクを表示する（4.9.2）', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.contact.index'))
        ->assertOk()
        ->assertSee(__('front.contact.title'))
        ->assertSee(__('front.contact.heading'))
        ->assertSee(__('front.contact.dummy_notice'))
        ->assertSee(route('front.recruit.index'));
});

it('入力フォームの各項目と種別の選択肢を表示する（4.9.2）', function () {
    createCinema('gion', '祇園ムビ');

    $component = Livewire::test(Index::class)
        ->assertSee(__('front.contact.fields.name'))
        ->assertSee(__('front.contact.fields.email'))
        ->assertSee(__('front.contact.fields.category'))
        ->assertSee(__('front.contact.fields.body'))
        ->assertSee(__('front.contact.submit'));

    foreach (ContactCategory::cases() as $category) {
        $component->assertSee(__($category->labelKey()));
    }
});

it('正しい入力で送信すると送信完了画面（P-15）へ遷移し、ダミーの文言を表示する（4.9.2）', function () {
    createCinema('gion', '祇園ムビ');

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('front.contact.complete'));

    $this->get(route('front.contact.complete'))
        ->assertOk()
        ->assertSee(__('front.contact.complete.heading'))
        ->assertSee(__('front.contact.dummy_notice'));
});

it('必須項目が未入力だとエラーになり、リダイレクトしない（4.9.2）', function (string $property) {
    createCinema('gion', '祇園ムビ');

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->set($property, '')
        ->call('submit')
        ->assertHasErrors([$property => 'required'])
        ->assertNoRedirect();
})->with(['name', 'email', 'category', 'body']);

it('氏名は50文字までとする', function () {
    createCinema('gion', '祇園ムビ');

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->set('name', str_repeat('あ', 51))
        ->call('submit')
        ->assertHasErrors(['name' => 'max'])
        ->assertNoRedirect();
});

it('形式が誤ったメールアドレスを拒否する', function () {
    createCinema('gion', '祇園ムビ');

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->set('email', 'not-an-email')
        ->call('submit')
        ->assertHasErrors(['email' => 'email'])
        ->assertNoRedirect();
});

it('本文は2000文字までとする', function () {
    createCinema('gion', '祇園ムビ');

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->set('body', str_repeat('あ', 2001))
        ->call('submit')
        ->assertHasErrors(['body' => 'max'])
        ->assertNoRedirect();
});

it('種別に選択肢以外の値を渡すとエラーになる（4.9.2）', function () {
    createCinema('gion', '祇園ムビ');

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->set('category', 'not-a-category')
        ->call('submit')
        ->assertHasErrors('category')
        ->assertNoRedirect();
});

it('送信してもメールを送信しない（4.9.2「メール送信およびDB保存は行わない」）', function () {
    createCinema('gion', '祇園ムビ');
    Mail::fake();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasNoErrors();

    Mail::assertNothingSent();
});

it('送信してもログへ本文・メールアドレスを出力しない（17.4.3）', function () {
    createCinema('gion', '祇園ムビ');
    Log::spy();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasNoErrors();

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
});

it('P-15 を送信を経ずに直接開くとP-14へリダイレクトする（4.9.2）', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.contact.complete'))
        ->assertRedirect(route('front.contact.index'));
});

it('P-15 はクロール対象外とする（19.3-6 / 4.9.5）', function () {
    createCinema('gion', '祇園ムビ');

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit');

    $this->get(route('front.contact.complete'))
        ->assertOk()
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('送信完了画面（P-15）は送信直後の1回だけ表示し、再読み込みするとP-14へ戻る（4.9.6）', function () {
    createCinema('gion', '祇園ムビ');

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertRedirect(route('front.contact.complete'));

    /*
     * 本番では Livewire の更新リクエストの終了時に StartSession がフラッシュを1段進める。
     * Livewire::test() はミドルウェアを通らないため、同じ処理をここで行う。
     */
    session()->ageFlashData();

    $this->get(route('front.contact.complete'))->assertOk();
    $this->get(route('front.contact.complete'))->assertRedirect(route('front.contact.index'));
});

it('送信しても入力値をセッションに残さない（4.9.6 / 17.4.3）', function () {
    createCinema('gion', '祇園ムビ');
    $input = validContactInput();

    Livewire::test(Index::class)
        ->set($input)
        ->call('submit')
        ->assertRedirect(route('front.contact.complete'));

    $stored = json_encode(session()->all(), JSON_UNESCAPED_UNICODE);

    expect($stored)->not->toContain($input['email'])
        ->and($stored)->not->toContain($input['body'])
        ->and($stored)->not->toContain($input['name']);
});
