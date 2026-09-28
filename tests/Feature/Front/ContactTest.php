<?php

use App\Enums\ContactCategory;
use App\Livewire\Front\Contact\Index;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * お問い合わせ（P-14・P-15、4.9.2。工程7-g）。ダミー実装のため、入力の検証と
 * 送信完了画面への遷移のみを固定する。メール送信・DB保存・ログ出力を一切行わないこと
 * （4.9.2 / 17.4.3）も検証する。
 *
 * ボット対策（Cloudflare Turnstile、4.9.2「ボット対策」/ 4.9.8。工程9-b）。
 * キーは `.env` の値に依存させず `config()` で明示する。本物の Cloudflare は一切呼ばない
 * （`Http::preventStrayRequests()` により、フェイクを置き忘れたテストは失敗する。テスト用の
 * シークレットキーは常に成功を返すため、実際に通信しても黙って通ってしまう）。
 */

beforeEach(function () {
    config([
        'services.turnstile.site_key' => '1x00000000000000000000AA',
        'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
    ]);

    Http::preventStrayRequests();
});

/**
 * siteverify への照会を常に成功として扱う既定のフェイク。
 *
 * **`beforeEach()` には置かない。** `Http::fake()` は先に登録した対象URLの応答が
 * 優先され、後から呼んでも上書きにならない（`stubCallbacks` は登録順に評価され、
 * 最初に一致した応答を返す。Laravel 13 で確認済み）。失敗応答を検証するテストは
 * 本ヘルパーを呼ばず、そのテスト内で直接 `Http::fake()` する。
 */
function fakeTurnstileSuccess(): void
{
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);
}

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
        'turnstileToken' => 'XXXX.DUMMY.TOKEN.XXXX',
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
    fakeTurnstileSuccess();

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
    fakeTurnstileSuccess();
    Mail::fake();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasNoErrors();

    Mail::assertNothingSent();
});

it('送信してもログへ本文・メールアドレスを出力しない（17.4.3）', function () {
    createCinema('gion', '祇園ムビ');
    fakeTurnstileSuccess();
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
    fakeTurnstileSuccess();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit');

    $this->get(route('front.contact.complete'))
        ->assertOk()
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
});

it('送信完了画面（P-15）は送信直後の1回だけ表示し、再読み込みするとP-14へ戻る（4.9.6）', function () {
    createCinema('gion', '祇園ムビ');
    fakeTurnstileSuccess();

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
    fakeTurnstileSuccess();
    $input = validContactInput();

    Livewire::test(Index::class)
        ->set($input)
        ->call('submit')
        ->assertRedirect(route('front.contact.complete'));

    $stored = json_encode(session()->all(), JSON_UNESCAPED_UNICODE);

    expect($stored)->not->toContain($input['email'])
        ->and($stored)->not->toContain($input['body'])
        ->and($stored)->not->toContain($input['name'])
        ->and($stored)->not->toContain($input['turnstileToken']);
});

it('P-14 のページに Turnstile のスクリプトとサイトキーが出力される（4.9.8）', function () {
    createCinema('gion', '祇園ムビ');

    $this->get(route('front.contact.index'))
        ->assertOk()
        ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', escape: false)
        ->assertSee('1x00000000000000000000AA', escape: false);
});

it('送信時に siteverify へシークレットキー・トークン・IPアドレスをフォーム形式で送る（4.9.2-4 / 4.9.8「検証の送信」）', function () {
    createCinema('gion', '祇園ムビ');
    fakeTurnstileSuccess();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasNoErrors();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request->isForm()
            && $request['secret'] === '1x0000000000000000000000000000000AA'
            && $request['response'] === 'XXXX.DUMMY.TOKEN.XXXX'
            && $request['remoteip'] === '127.0.0.1';
    });
});

it('トークンが空だとturnstileTokenが必須エラーになり、Cloudflareを呼ばない（4.9.8）', function () {
    createCinema('gion', '祇園ムビ');
    Http::fake();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->set('turnstileToken', '')
        ->call('submit')
        ->assertHasErrors(['turnstileToken' => 'required'])
        ->assertNoRedirect();

    Http::assertNothingSent();
});

it('2048文字を超えるトークンはエラーとし、Cloudflareへ送らない（4.9.8「入力の検証規則」）', function () {
    createCinema('gion', '祇園ムビ');
    Http::fake();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->set('turnstileToken', str_repeat('a', 2049))
        ->call('submit')
        ->assertHasErrors(['turnstileToken' => 'max'])
        ->assertNoRedirect();

    Http::assertNothingSent();
});

it('siteverifyがsuccess:falseを返すと送信を通さず、トークンを空に戻してウィジェットのやり直しを指示する（4.9.8「失敗時の扱い」）', function () {
    createCinema('gion', '祇園ムビ');
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
    ]);
    Log::spy();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasErrors(['turnstileToken'])
        ->assertSee(__('front.contact.errors.turnstile_failed'))
        ->assertSet('turnstileToken', '')
        ->assertDispatched('turnstile-reset')
        ->assertNoRedirect();

    // 失敗時もログを出さない（4.9.8「ログ」）。
    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
});

it('確認の失敗だけが残る場合、エラー要約を「入力の誤り」として数えず確認の文言を出す（20.3-2）', function () {
    createCinema('gion', '祇園ムビ');
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasErrors(['turnstileToken'])
        ->assertDontSee(__('front.contact.errors.summary', ['count' => 1]))
        ->assertSee(__('front.contact.errors.jump_to_turnstile'))
        ->assertSeeHtml('href="#contact-turnstileToken"');
});

it('siteverifyへの通信に失敗した場合も送信を通さない（fail closed。4.9.8「失敗時の扱い」）', function (string $case) {
    createCinema('gion', '祇園ムビ');

    Http::fake([
        'challenges.cloudflare.com/*' => match ($case) {
            'connection' => Http::failedConnection(),
            'server_error' => Http::response([], 500),
        },
    ]);

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasErrors(['turnstileToken'])
        ->assertNoRedirect();
})->with(['connection', 'server_error']);

it('入力項目に誤りがあるとCloudflareを呼ばない（トークンを消費させない。4.9.8「構成」）', function () {
    createCinema('gion', '祇園ムビ');
    Http::fake();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->set('name', '')
        ->call('submit')
        ->assertHasErrors(['name' => 'required'])
        ->assertSet('turnstileToken', 'XXXX.DUMMY.TOKEN.XXXX')
        ->assertNotDispatched('turnstile-reset')
        ->assertSee(__('front.contact.errors.summary', ['count' => 1]))
        ->assertNoRedirect();

    Http::assertNothingSent();
});

it('キーが片方でも未設定なら、ウィジェットと api.js を出さず送信できない旨を案内する（4.9.8「キー未設定時」）', function (string $missingKey) {
    createCinema('gion', '祇園ムビ');
    config(["services.turnstile.{$missingKey}" => '']);

    $this->get(route('front.contact.index'))
        ->assertOk()
        ->assertDontSee('challenges.cloudflare.com/turnstile', escape: false);

    Livewire::test(Index::class)
        ->assertSee(__('front.contact.turnstile.unavailable'))
        ->assertDontSee('turnstile.render', escape: false);
})->with(['site_key', 'secret_key']);

it('キーが片方でも未設定なら、Cloudflareを呼ばず、確認のやり直しを促さずに送信を止める（4.9.8「キー未設定時」/ 20.3-3）', function (string $missingKey) {
    createCinema('gion', '祇園ムビ');
    config(["services.turnstile.{$missingKey}" => '']);
    Http::fake();

    Livewire::test(Index::class)
        ->set(validContactInput())
        ->call('submit')
        ->assertHasErrors(['turnstileToken'])
        ->assertSee(__('front.contact.turnstile.unavailable'))
        ->assertDontSee(__('front.contact.errors.turnstile_failed'))
        ->assertNotDispatched('turnstile-reset')
        ->assertNoRedirect();

    Http::assertNothingSent();
})->with(['site_key', 'secret_key']);
