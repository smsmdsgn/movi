<?php

namespace App\Livewire\Front\Reservation;

use App\Livewire\Front\Reservation\Concerns\GuardsReservationStep;
use App\Livewire\Front\Reservation\Concerns\ResolvesScreening;
use App\Livewire\Front\Reservation\Concerns\UsesReservationDraft;
use App\Models\Screening;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Services\SeatLockService;
use App\Services\StripeException;
use App\Services\StripeService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 決済（P-36、7.11）。1画面1コンポーネント（13.4.3）。
 *
 * **本画面は課金しない。** カード情報はブラウザが Stripe Elements で直接 Stripe へ送り、
 * 本システムは PaymentMethod のID（`pm_...`）だけを受け取って持ち越す（17.3-1）。
 * 課金（PaymentIntent の作成・確認）と予約確定は、確定ボタンを持つ予約確認（P-37、7.12）
 * が単一の操作として行う（8.2 / 4.3.14）。決済だけが成立して予約が確定していない状態を、
 * 画面遷移をまたいで残さないため。
 *
 * 支払方法の選択（7.11.1）は Alpine のみで完結させる。クレジットカード以外は案内を出す
 * だけで先へ進めず、サーバーが知る必要のある状態にならない。
 *
 * 金額は保持せず、描画のたびに `PricingService` へ計算させる（13.4.5。P-35 と同じ）。
 * 券種の割り当てはクライアントからではなく `ReservationDraft` から読む。
 */
class Payment extends Component
{
    use GuardsReservationStep;
    use ResolvesScreening;
    use UsesReservationDraft;

    /** 表示中の案内の文言キー（7.17）。言語ファイルのキーを差し替えられないよう `Locked`。 */
    #[Locked]
    public ?string $messageKey = null;

    /** 同一リクエスト内での再計算を避けるための保持（Livewire は private を直列化しない）。 */
    private ?PriceBreakdown $resolvedBreakdown = null;

    private bool $breakdownResolved = false;

    public function mount(Screening $screening): void
    {
        $this->rememberScreening($screening);

        $locks = app(SeatLockService::class);

        if (! $this->canProceed($locks)) {
            return;
        }

        // 座席ロックを15分へ延長する（6.4.1-4 / 4.3.8）。カード入力に10分は短い。
        $locks->extend($locks->holderKey(), SeatLockService::PAYMENT_LOCK_MINUTES);

        // 支払金額が0円の予約は決済を通さない（4.5.2「決済のスキップ条件」）。無料鑑賞券
        // だけでなく、割引のみで0円になる回も同じ扱いとする（12章 残課題29 の解消）。
        if ($this->breakdown()?->isFullyCovered() === true) {
            $this->forwardToConfirm();
        }
    }

    /**
     * ブラウザが Stripe から受け取った PaymentMethod を持ち越す（7.11.1）。
     *
     * **申告された値をそのまま信用しない**（17章）。形式を確かめたうえで Stripe に実在する
     * カードかを問い合わせ、確認できたIDだけをセッションへ記録する。誤りをこの画面で
     * 検出できれば、利用者はカードの入力し直しで済む（課金の時点まで持ち越さない）。
     *
     * 引数の型を `string` と書かない。**値を決めるのはクライアント**であり、配列を送られると
     * 型エラー（500）になる（P-35 の `$selections` と同じ理由）。
     */
    public function preparePayment(mixed $paymentMethodId): void
    {
        $this->messageKey = null;

        $locks = app(SeatLockService::class);

        // 判定順は 前提 → 決済 とする。座席を失った利用者にカードの誤りを指摘しても、
        // 次の行動（座席の選び直し）につながらない（P-35 と同じトーン）。
        if (! $this->canProceed($locks)) {
            return;
        }

        $screening = $this->screeningOnSale();

        // `canProceed()` が真なら販売期間内の上映回が存在する。PHPStan へ示すための分岐。
        if ($screening === null) {
            return;
        }

        $stripe = app(StripeService::class);

        // キー未設定（15.1）。画面は常設の案内（中立の配色）を出しているため、ここで
        // `messageKey` を立てない。立てると同じ文言が、利用者の操作の失敗を示す赤枠と
        // 常設の案内の2箇所に出る（4.3.10「販売できない状態は中立の配色」と同じ整理）。
        if (! $stripe->isConfigured()) {
            return;
        }

        if (! is_string($paymentMethodId)) {
            $this->messageKey = 'front.reservation.errors.payment_failed';

            return;
        }

        try {
            $usable = $stripe->isUsableCard($paymentMethodId);
        } catch (StripeException $exception) {
            $this->messageKey = $exception->messageKey;

            return;
        }

        if (! $usable) {
            $this->messageKey = 'front.reservation.errors.payment_failed';

            return;
        }

        $this->draft()->putPaymentMethod($screening, $paymentMethodId);

        $this->forwardToConfirm();
    }

    public function render(SeatLockService $locks): View
    {
        $status = $this->stepStatus($locks);
        $stripe = app(StripeService::class);
        $canProceed = $status['noticeKey'] === null;

        return view('front.reservation.payment-form', [
            'recoveryUrl' => $status['recoveryUrl'],
            'recoveryLabelKey' => $status['recoveryLabelKey'],
            'onSale' => $this->screeningOnSale() !== null,
            'canProceed' => $canProceed,
            // 前提が崩れている場合はそちらを優先する（座席を失った利用者に「お支払いを
            // 完了できませんでした」を出さない。P-35 の `noticeKey` と同じ整理）。
            'noticeKey' => $status['noticeKey'] ?? $this->messageKey,
            // **前提が崩れている間は計算しない。** 券種が券種マスタから消えた場合、
            // `PricingService` は例外を投げる（13.4.5）。前提の判定（`stepStatus()`）が
            // その状態を弾いているため、判定を通った場合にだけ計算する。
            'total' => $canProceed ? $this->breakdown()?->total() : null,
            // キーが未設定の環境では Elements を出さず案内だけを出す（8.1 の TMDB と同じ扱い）。
            'stripeConfigured' => $stripe->isConfigured(),
            'publishableKey' => $stripe->publishableKey(),
            // 戻り先は直前の P-35（券種選択）。前提未充足時の復帰先と同じ先に揃う。
            'ticketsUrl' => route('front.reservation.tickets', ['id' => $this->screeningId]),
        ]);
    }

    /**
     * 券種の割り当て（P-35）を前提とする。支払金額が定まらないまま決済へ進めない。
     */
    protected function requiresTicketSelection(): bool
    {
        return true;
    }

    /**
     * 予約確認（P-37、7.12）へ送る。課金と予約確定はその画面の確定ボタンが行う（4.3.14）。
     */
    private function forwardToConfirm(): void
    {
        $this->redirect(route('front.reservation.confirm', ['id' => $this->screeningId]), navigate: false);
    }

    /**
     * 支払金額（7.11）。券種の割り当てが揃っていない場合は null を返す。
     *
     * 割り当ては `ReservationDraft`（P-35 が記録したもの）から読む。**クライアントから
     * 受け取らない**（17章）。保持中の全席ぶんが揃っていることは `GuardsReservationStep`
     * が前提として確かめているため、ここでは記録をそのまま用いる。
     */
    private function breakdown(): ?PriceBreakdown
    {
        if ($this->breakdownResolved) {
            return $this->resolvedBreakdown;
        }

        $this->breakdownResolved = true;

        $screening = $this->screeningOnSale();

        if ($screening === null) {
            return null;
        }

        $locks = app(SeatLockService::class);
        $heldSeatIds = $locks->heldSeatIds($screening, $locks->holderKey());
        $tickets = array_intersect_key($this->draft()->tickets($this->screeningId), array_flip($heldSeatIds));

        if ($tickets === [] || count($tickets) !== count($heldSeatIds)) {
            return null;
        }

        return $this->resolvedBreakdown = app(PricingService::class)->calculate($screening, $tickets);
    }
}
