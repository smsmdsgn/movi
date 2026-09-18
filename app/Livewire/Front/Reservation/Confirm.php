<?php

namespace App\Livewire\Front\Reservation;

use App\Enums\PaymentOutcome;
use App\Enums\ReservationStatus;
use App\Livewire\Front\Reservation\Concerns\GuardsReservationStep;
use App\Livewire\Front\Reservation\Concerns\ResolvesScreening;
use App\Livewire\Front\Reservation\Concerns\UsesReservationDraft;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\TicketType;
use App\Models\User;
use App\Services\PaymentAttempt;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Services\Purchaser;
use App\Services\ReservationService;
use App\Services\SeatLockService;
use App\Services\StripeService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 予約確認（P-37、7.12）。1画面1コンポーネント（13.4.3）。
 *
 * **確定ボタンが課金と予約確定を1つの操作として行う**（4.3.14 / 8.2）。決済画面（P-36）は
 * カードを受け取るだけで課金しないため、「課金済みだが予約は `pending`」の状態が画面遷移を
 * またいで残らない。処理そのものは `ReservationService` に集約する（13.4.7）。
 *
 * **追加認証（3Dセキュア）に対応する。** `ReservationService` が `requires_action` を返した
 * 場合は `clientSecret` をブラウザへ渡し、`handleNextAction()` の完了後に
 * `completeAuthentication()` を呼ばせる。**完了の通知は信用せず**、サーバーが PaymentIntent
 * を取り直して `status` と金額を検証する（17.3-3）。
 *
 * 金額・券種はクライアントから受け取らず、`ReservationDraft` と `PricingService` から
 * 組み立て直す（13.4.5 / 17.3-2）。
 */
class Confirm extends Component
{
    use GuardsReservationStep;
    use ResolvesScreening;
    use UsesReservationDraft;

    /**
     * 直前の試行で作成した `pending` 予約（4.3.3）。
     *
     * 追加認証からの再開に用いるほか、**確定ボタンの二度押しで予約と PaymentIntent が
     * 二重に作られること**を防ぐ。クライアントから差し替えられないよう `Locked`。
     */
    #[Locked]
    public ?int $pendingReservationId = null;

    /**
     * 追加認証（3Dセキュア）の完了を待っているか。
     *
     * **client secret は公開プロパティに持たない。** ブラウザが必要とするのは認証を
     * 開始する一度きりであり、`dispatch()` の引数で渡せば足りる。状態として持ち回ると、
     * 以後のすべての更新で線に載る。
     */
    #[Locked]
    public bool $awaitingAuthentication = false;

    /** 表示中の案内の文言キー（7.17）。 */
    #[Locked]
    public ?string $messageKey = null;

    /** 確定後の復帰先（座席選択）を出すか。返金を伴う失敗（8.2）で立てる。 */
    #[Locked]
    public bool $seatsLost = false;

    private ?PriceBreakdown $resolvedBreakdown = null;

    private bool $breakdownResolved = false;

    /** @var EloquentCollection<int, Seat>|null */
    private ?EloquentCollection $resolvedSeats = null;

    /** @var EloquentCollection<int, TicketType>|null */
    private ?EloquentCollection $resolvedTicketTypes = null;

    public function mount(Screening $screening): void
    {
        $this->rememberScreening($screening);
    }

    /**
     * 予約を確定する（7.12-6 / 8.2）。
     *
     * 判定順は 前提 → 課金 とする。座席を失った利用者に決済の結果を示しても、
     * 次の行動（座席の選び直し）につながらない（P-35・P-36 と同じトーン）。
     */
    public function confirm(SeatLockService $locks): void
    {
        $this->messageKey = null;
        // 直前の結果は持ち越さない（4.3.10「描画時点の状態を優先する」）。
        $this->seatsLost = false;

        if (! $this->canProceed($locks)) {
            return;
        }

        $screening = $this->screeningOnSale();
        $breakdown = $this->breakdown();
        $purchaser = $this->purchaser();

        // `canProceed()` が真なら上映回・券種・金額・購入者がすべて揃っている。
        // PHPStan へ示すための分岐。
        if ($screening === null || $breakdown === null || $purchaser === null) {
            return;
        }

        $attempt = app(ReservationService::class)->payAndConfirm(
            $screening,
            $breakdown,
            $purchaser,
            $locks->holderKey(),
            $this->draft()->paymentMethodId($this->screeningId),
            $this->pendingReservation(),
        );

        $this->handle($attempt);
    }

    /**
     * 追加認証（3Dセキュア）の完了後に呼ばれる（ブラウザの `handleNextAction()` の後）。
     *
     * **認証が成功したかどうかはブラウザに判断させない。** 本メソッドは通知を受け取る
     * だけで、成否はサーバーが PaymentIntent を取り直して判断する（17.3-3）。認証に
     * 失敗した場合も同じ経路を通り、決済失敗として扱われる。
     */
    public function completeAuthentication(SeatLockService $locks): void
    {
        $this->messageKey = null;
        $this->awaitingAuthentication = false;

        $reservation = $this->pendingReservation();

        if ($reservation === null) {
            $this->messageKey = 'front.reservation.errors.payment_failed';

            return;
        }

        $screening = $this->screeningOnSale();
        $breakdown = $this->breakdown();

        // **前提が崩れていても課金を放置しない。** 認証に時間がかかって座席ロックが
        // 切れた場合、この時点で課金だけが成立していることがある。後始末（成立して
        // いれば返金、していなければ取り消し）をサービスに委ねる（8.2）。
        if ($screening === null || $breakdown === null) {
            $this->handle(app(ReservationService::class)->abandon($reservation));

            return;
        }

        $this->handle(app(ReservationService::class)->completeAuthentication(
            $reservation,
            $screening,
            $breakdown,
            $locks->holderKey(),
        ));
    }

    public function render(SeatLockService $locks): View
    {
        $status = $this->stepStatus($locks);
        $breakdown = $status['noticeKey'] === null ? $this->breakdown() : null;
        // **金額が求まらない状態を「進める」としない。** 前提の判定と金額の算出は別の
        // 読み取りであり、その間にロックが切れると前者だけが真になりうる。ビューが
        // `$breakdown` を参照するため、例外ではなく 7.17 の文言へ倒す（4.3.10）。
        $canProceed = $status['noticeKey'] === null && $breakdown !== null;

        $seatsUrl = route('front.reservation.seats', ['id' => $this->screeningId]);

        return view('front.reservation.confirm-form', [
            // 前提は満たしているのに金額が求まらない場合（読み取りの隙にロックが切れた）は、
            // 座席選択への導線を出す。
            'recoveryUrl' => $status['recoveryUrl'] ?? ($canProceed ? null : $seatsUrl),
            'recoveryLabelKey' => $status['recoveryLabelKey'] ?? 'front.reservation.back_to_seats',
            'onSale' => $this->screeningOnSale() !== null,
            'canProceed' => $canProceed,
            // **返金を伴う結果は前提の案内より優先する。** 課金と返金が起きたことは、
            // 「確保期限が過ぎました」より先に伝えるべき事実である（座席を失った結果
            // として前提も崩れているため、両方が同時に成り立つ）。
            'noticeKey' => $this->seatsLost
                ? $this->messageKey
                : ($status['noticeKey'] ?? $this->messageKey ?? ($canProceed ? null : 'front.reservation.errors.lock_expired')),
            'breakdown' => $breakdown,
            'seats' => $canProceed ? $this->heldSeats() : new EloquentCollection,
            'ticketTypes' => $this->ticketTypes(),
            'purchaser' => $this->purchaserDisplay(),
            // 7.12-5 座席ロックの残り時間。期限そのものを渡し、表示はブラウザが刻む。
            'holdExpiresAt' => $canProceed ? $this->holdExpiresAt($locks) : null,
            'publishableKey' => app(StripeService::class)->publishableKey(),
            // 返金を伴う失敗（8.2）では座席選択からやり直す。
            'seatsUrl' => $seatsUrl,
            'seatsLost' => $this->seatsLost,
            // カードを変えたい場合の導線（決済失敗時にも出す）。
            'paymentUrl' => route('front.reservation.payment', ['id' => $this->screeningId]),
        ]);
    }

    /**
     * 支払方法（P-36）を前提とするか。**支払金額が0円の予約は通らない**（4.5.2 / 4.3.14）。
     */
    protected function requiresPaymentMethod(): bool
    {
        return $this->breakdown()?->isFullyCovered() === false;
    }

    /**
     * 券種の割り当て（P-35）を前提とする。支払金額が定まらないまま確定できない。
     */
    protected function requiresTicketSelection(): bool
    {
        return true;
    }

    /**
     * 試行の結果に応じて画面を進める（8.2）。
     */
    private function handle(PaymentAttempt $attempt): void
    {
        match ($attempt->outcome) {
            PaymentOutcome::Confirmed => $this->forwardToComplete($attempt),
            PaymentOutcome::RequiresAuthentication => $this->awaitAuthentication($attempt),
            // 同じ画面で再試行できる（8.2「決済失敗時の扱い」）。**予約を引き継ぐかは
            // サービスが決める**（通信の失敗は同じ冪等キーで送り直し、カードの拒否は
            // 作り直す。17.3-4 / 4.3.15）。
            PaymentOutcome::Failed => $this->failWith($attempt->messageKey, seatsLost: false, pending: $attempt->reservation),
            // 課金の成立後に座席を確保できなかった。返金済み（8.2 手順1）。
            PaymentOutcome::SeatsUnavailable => $this->failWith($attempt->messageKey, seatsLost: true),
        };
    }

    private function forwardToComplete(PaymentAttempt $attempt): void
    {
        $reservationNo = $attempt->reservation?->reservation_no;

        if ($reservationNo === null) {
            // 確定できていれば必ず予約番号がある。取り違えたURL（`/reservations//complete`）を
            // 静かに作らないよう、ここで決済失敗として扱う。
            $this->failWith('front.reservation.errors.payment_failed', seatsLost: false);

            return;
        }

        // 確定した時点で持ち越す内容は無い。残すと完了画面から戻った利用者が
        // 同じ内容で確定をもう一度試みる経路になる。
        $this->draft()->clear();

        $this->redirect(route('front.reservation.complete', ['no' => $reservationNo]), navigate: false);
    }

    /**
     * 追加認証（3Dセキュア）をブラウザへ委ねる。
     *
     * client secret はイベントの引数としてのみ渡す。ブラウザは `handleNextAction()` を
     * 呼んだのち、成否にかかわらず `completeAuthentication()` を呼び戻す（判断はサーバー）。
     */
    private function awaitAuthentication(PaymentAttempt $attempt): void
    {
        $this->pendingReservationId = $attempt->reservation?->id;
        $this->awaitingAuthentication = true;

        $this->dispatch('payment-authentication-required', clientSecret: (string) $attempt->clientSecret);
    }

    private function failWith(?string $messageKey, bool $seatsLost, ?Reservation $pending = null): void
    {
        $this->messageKey = $messageKey ?? 'front.reservation.errors.payment_failed';
        $this->awaitingAuthentication = false;
        $this->pendingReservationId = $pending?->id;
        $this->seatsLost = $seatsLost;
    }

    /**
     * 直前の試行で作成した `pending` 予約。他人の予約を渡されないよう、上映回と
     * 状態でも絞る（`pendingReservationId` は `Locked` だが、ここでも確かめる）。
     */
    private function pendingReservation(): ?Reservation
    {
        if ($this->pendingReservationId === null) {
            return null;
        }

        return Reservation::query()
            ->whereKey($this->pendingReservationId)
            ->where('screening_id', $this->screeningId)
            ->where('status', ReservationStatus::Pending)
            ->first();
    }

    /**
     * 購入者（4.3.2）。会員は `users`、非会員は P-34 の入力を用いる。
     *
     * 非会員の入力は `GuardsReservationStep` が前提として確認済みだが（4.3.14）、
     * **空の連絡先で予約を作らない**ため、ここでも欠けていれば確定させない。
     */
    private function purchaser(): ?Purchaser
    {
        $user = Auth::user();

        if ($user instanceof User) {
            return Purchaser::member($user);
        }

        $guest = $this->draft()->guest($this->screeningId);

        return $guest === null ? null : Purchaser::guest($guest);
    }

    /**
     * 画面に出す購入者情報（7.12-4）。
     *
     * @return array{name: string, email: string, phone: string}
     */
    private function purchaserDisplay(): array
    {
        $user = Auth::user();

        if ($user instanceof User) {
            return ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];
        }

        $guest = $this->draft()->guest($this->screeningId);

        return [
            'name' => $guest['name'] ?? '',
            'email' => $guest['email'] ?? '',
            'phone' => $guest['phone'] ?? '',
        ];
    }

    /**
     * 保持中のロックの期限のうち最も早いもの（7.12-5）。
     */
    private function holdExpiresAt(SeatLockService $locks): ?CarbonImmutable
    {
        $screening = $this->screeningOnSale();

        if ($screening === null) {
            return null;
        }

        return $locks->holdExpiresAt($screening, $locks->holderKey());
    }

    /**
     * 支払金額（7.12-3）。券種の割り当ては `ReservationDraft` から読む（17.3-2）。
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

        // 券種が消えている場合は `GuardsReservationStep` が前提として弾く（4.3.14）。
        if ($this->ticketTypes()->whereIn('id', array_unique(array_values($tickets)))->count()
            !== count(array_unique(array_values($tickets)))) {
            return null;
        }

        return $this->resolvedBreakdown = app(PricingService::class)->calculate($screening, $tickets);
    }

    /**
     * 保持中の座席（6.4.3-2）。座席表（P-31）と同じ並び順で表示する。
     *
     * @return EloquentCollection<int, Seat>
     */
    private function heldSeats(): EloquentCollection
    {
        if ($this->resolvedSeats !== null) {
            return $this->resolvedSeats;
        }

        $screening = $this->screeningOnSale();

        if ($screening === null) {
            return $this->resolvedSeats = new EloquentCollection;
        }

        $locks = app(SeatLockService::class);
        $heldSeatIds = $locks->heldSeatIds($screening, $locks->holderKey());

        if ($heldSeatIds === []) {
            return $this->resolvedSeats = new EloquentCollection;
        }

        return $this->resolvedSeats = Seat::query()
            ->whereIn('id', $heldSeatIds)
            ->orderBy('grid_row')
            ->orderBy('grid_col')
            ->get();
    }

    /**
     * 券種マスタ（6.5.1）。座席ごとの券種名の表示に使う。
     *
     * @return EloquentCollection<int, TicketType>
     */
    private function ticketTypes(): EloquentCollection
    {
        return $this->resolvedTicketTypes ??= TicketType::query()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }
}
