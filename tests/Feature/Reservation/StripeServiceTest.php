<?php

use App\Services\StripeException;
use App\Services\StripeService;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentMethod;
use Stripe\Service\PaymentMethodService;
use Stripe\StripeClient;

/*
 * Stripe 連携（8.2 / 17.3）。決済画面（P-36）が受け取った PaymentMethod の検証を固定する。
 * 実際の通信は行わず、`StripeClient` を差し替える。
 */

/**
 * Stripe クライアントを差し替える。`$configure` で `paymentMethods` の応答を組み立てる。
 */
function fakeStripeClient(Closure $configure): void
{
    config(['services.stripe.key' => 'pk_test_dummy', 'services.stripe.secret' => 'sk_test_dummy']);

    $paymentMethods = Mockery::mock(PaymentMethodService::class);
    $configure($paymentMethods);

    // `$client->paymentMethods` は `__get()` から `getService()` へ委譲される。Mockery は
    // マジックメソッドを差し替えないため、委譲先の `getService()` に期待を置く。
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('getService')->with('paymentMethods')->andReturn($paymentMethods);

    app()->instance(StripeClient::class, $client);
}

it('カードの PaymentMethod を使用できると判定する', function () {
    fakeStripeClient(function ($paymentMethods) {
        $paymentMethods->shouldReceive('retrieve')
            ->once()
            ->with('pm_card_visa')
            ->andReturn(PaymentMethod::constructFrom(['id' => 'pm_card_visa', 'type' => 'card']));
    });

    expect(app(StripeService::class)->isUsableCard('pm_card_visa'))->toBeTrue();
});

it('カード以外の支払方法は使用できないと判定する（7.11.1）', function () {
    fakeStripeClient(function ($paymentMethods) {
        $paymentMethods->shouldReceive('retrieve')
            ->andReturn(PaymentMethod::constructFrom(['id' => 'pm_bank', 'type' => 'us_bank_account']));
    });

    expect(app(StripeService::class)->isUsableCard('pm_bank'))->toBeFalse();
});

it('形式が不正なIDは Stripe へ問い合わせずに拒否する（17.5.1）', function (string $paymentMethodId) {
    // IDはリクエストURLのパスに連結されるため、`/` や `?` を含む値を渡さない。
    fakeStripeClient(function ($paymentMethods) {
        $paymentMethods->shouldNotReceive('retrieve');
    });

    expect(app(StripeService::class)->isUsableCard($paymentMethodId))->toBeFalse();
})->with([
    '空文字' => [''],
    '接頭辞が異なる' => ['pi_1234567890'],
    'パスを含む' => ['pm_123/../charges'],
    'クエリを含む' => ['pm_123?expand=customer'],
]);

it('存在しないIDは入力のやり直しとして扱う（例外にしない）', function () {
    fakeStripeClient(function ($paymentMethods) {
        $paymentMethods->shouldReceive('retrieve')->andThrow(new InvalidRequestException('No such payment_method'));
    });

    expect(app(StripeService::class)->isUsableCard('pm_missing'))->toBeFalse();
});

it('通信の失敗は StripeException へ置き換える（17.9-1）', function () {
    fakeStripeClient(function ($paymentMethods) {
        $paymentMethods->shouldReceive('retrieve')->andThrow(new ApiConnectionException('timeout to api.stripe.com'));
    });

    expect(fn () => app(StripeService::class)->isUsableCard('pm_card_visa'))
        ->toThrow(StripeException::class);
});

it('キーが未設定の場合は Stripe を呼ばずに StripeException を送出する（15.1）', function () {
    config(['services.stripe.key' => null, 'services.stripe.secret' => null]);

    $service = app(StripeService::class);

    expect($service->isConfigured())->toBeFalse();
    expect(fn () => $service->isUsableCard('pm_card_visa'))->toThrow(StripeException::class);
});

it('公開可能キーとシークレットキーの双方が揃って初めて決済を提供できる', function (?string $key, ?string $secret, bool $expected) {
    config(['services.stripe.key' => $key, 'services.stripe.secret' => $secret]);

    expect(app(StripeService::class)->isConfigured())->toBe($expected);
})->with([
    '双方あり' => ['pk_test_dummy', 'sk_test_dummy', true],
    '公開可能キーのみ' => ['pk_test_dummy', null, false],
    'シークレットキーのみ' => [null, 'sk_test_dummy', false],
    '空白のみ' => ['   ', '   ', false],
]);
