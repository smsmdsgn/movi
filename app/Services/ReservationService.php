<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 予約の確定（13.4.7 / 8.2）。課金と `t_reservations` の確定を集約する。
 *
 * **課金と確定は予約確認（P-37）の確定ボタンが1つの操作として行う**（4.3.14）。
 * 決済画面（P-36）はカードを受け取るだけで課金しない。
 *
 * 手順は次の3段である。**外部との通信をデータベースのトランザクションの内側に置かない。**
 * 行ロックを保持したまま Stripe の応答を待つと、同じ上映回の他の顧客の座席取得が
 * 通信時間ぶん直列化される（4.3.8 と同じ理由）。
 *
 * | # | 段 | 内容 |
 * |---|---|---|
 * | 1 | 予約の作成 | `pending` の `t_reservations` を作る。課金前に作るのは、課金が成立した後に応答を失っても、予約番号と PaymentIntent のIDが残るようにするため。期限切れは B-02（10章）が `expired` にする |
 * | 2 | 課金 | `StripeService::chargeCard()`。追加認証（3Dセキュア）が要る場合はここで中断し、ブラウザの認証後に `completeAuthentication()` で再開する |
 * | 3 | 確定 | 単一トランザクションで、ロックの再検証 → `paid` への更新と入場コードの採番 → `t_reservation_seats` の作成 → 座席ロックの削除（8.2 手順1〜4） |
 *
 * 手順3が失敗した場合（ロックの喪失・一意制約違反）は**ロールバックのうえ返金**し、
 * 座席選択からの再実行を促す（8.2）。**返金は予約1件につき1回**とし、
 * `t_reservations.refunded_at` で二重返金を防ぐ（17.3-5）。
 *
 * 無料鑑賞券の消費（8.2 手順5）は未実装（12章 残課題31）。`t_reservations.free_ticket_id`
 * は常に null であり、使用状態は 6.1.2 の結論に従い `active_free_ticket_id`（生成列）を
 * 単一の真実源とする。実装時は本サービスの手順3へ加えること。
 *
 * **キャンセル（4.4）も本サービスが持つ**（`cancel()`、工程5-o）。確定と同じく金銭に
 * 触れるため、返金の実行（`refundOnce()`）を1箇所に集約している。確定が「返金してから
 * 諦める」のに対し、キャンセルは「DBを確定させてから返金する」点で順序が逆になる
 * （4.3.18）。
 */
class ReservationService
{
    /** 予約番号（4.3.5）の桁数。 */
    private const int RESERVATION_NO_DIGITS = 8;

    /** 入場コード（4.6.2-1）の文字数。 */
    private const int ENTRY_CODE_LENGTH = 32;

    /** 予約番号・入場コードの採番の再試行回数。 */
    private const int CODE_ATTEMPTS = 10;

    /**
     * 確定トランザクションの試行回数。
     *
     * 同じ上映回行を `SeatLockService::acquire()` も掴むため（4.3.8）、ロック待ちの
     * 競合とデッドロックは通常運用で起こりうる。課金の後に1回の失敗で諦めると、
     * 返金を伴う経路へ落ちる頻度が上がる。
     */
    private const int TRANSACTION_ATTEMPTS = 3;

    /**
     * B-02 が1回の実行で無効化する予約の上限（10章）。
     *
     * **1件ごとに Stripe へ問い合わせる可能性がある**（課金が残っていないことを確かめる
     * ため。4.3.19）。B-03 の上限（1000件）より小さくするのは、外部との通信が1件ずつ
     * 直列に入るためである。取りこぼした分は次回の実行が拾う。
     */
    public const int EXPIRE_PER_RUN_LIMIT = 100;

    public function __construct(
        private readonly StripeService $stripe,
        private readonly SeatLockService $locks,
    ) {}

    /**
     * 予約を作成して課金し、確定させる（8.2）。
     *
     * **支払金額が0円の場合は課金しない**（4.5.2「決済のスキップ条件」）。由来は問わず、
     * 無料鑑賞券でも割引でも同じ扱いとする（4.3.14）。
     *
     * `$pending` に既存の `pending` 予約を渡すと、それを使い回す。確定ボタンの二度押しで
     * 予約と PaymentIntent が二重に作られることを防ぐため、画面は直前の試行で得た予約を
     * 渡すこと。**カードを入れ直しての再試行では渡さない**（同じ冪等キーで Stripe が
     * 最初の失敗を返し続ける）。
     */
    public function payAndConfirm(
        Screening $screening,
        PriceBreakdown $breakdown,
        Purchaser $purchaser,
        string $holderKey,
        ?string $paymentMethodId,
        ?Reservation $pending = null,
    ): PaymentAttempt {
        $amount = $breakdown->total();

        // 座席0件の予約を作らない。金額も座席も無い `paid` が生まれ、入場コードだけが
        // 採番される（`PriceBreakdown::isFullyCovered()` が `seats !== []` を条件に
        // 入れているのと同じ趣旨）。画面側でも到達しないが、確定の集約点で閉じる。
        if ($breakdown->seats === []) {
            return PaymentAttempt::failed('front.reservation.errors.lock_expired');
        }

        if ($amount > 0 && $paymentMethodId === null) {
            return PaymentAttempt::failed('front.reservation.errors.payment_failed');
        }

        // **期限内のロックが1件も無ければ課金しない。** 確定は必ず失敗するうえ、期限を
        // 持たない `pending` 予約（B-02 の対象外）が残る。
        $expiresAt = $this->locks->holdExpiresAt($screening, $holderKey);

        if ($expiresAt === null) {
            return PaymentAttempt::failed('front.reservation.errors.lock_expired');
        }

        $reservation = $this->reusable($pending, $screening, $amount);

        if ($reservation !== null) {
            // **使い回す予約の期限を現在のロックに合わせて引き直す。** ロックは P-36 への
            // 再入場（`extend()`）や座席の取り直しで延びる一方、`expires_at` は作成時の
            // 値のままである。古い期限を残すと、**座席を押さえているのに期限切れに見え**、
            // `Reservation::active()` が有効と認めず B-02 が倒しにかかる（4.3.19）。
            // 保存の失敗は無視する（次の確定で引き直す。倒されても課金の前である）。
            $reservation->expires_at = $expiresAt;
            $this->persist($reservation);
        } else {
            $reservation = $this->createPending($screening, $breakdown, $purchaser, $expiresAt);
        }

        if ($amount === 0) {
            return $this->finalizeOrRefund($reservation, $screening, $breakdown, $holderKey, charged: false);
        }

        // PHPStan へ示すための分岐（`$amount > 0` かつ null なら上で戻っている）。
        if ($paymentMethodId === null) {
            return PaymentAttempt::failed('front.reservation.errors.payment_failed');
        }

        try {
            $charge = $this->stripe->chargeCard(
                $amount,
                $paymentMethodId,
                'reservation:'.$reservation->id,
                // 個人情報を載せない（17.4.3）。予約番号は問い合わせ時の照合に使う。
                ['reservation_no' => $reservation->reservation_no],
            );
        } catch (StripeException $exception) {
            // **通信の失敗では予約を捨てない。** 課金が成立したかどうかが不明であり、
            // 予約を作り直すと冪等キーが変わって二重課金になる（17.3-4）。同じ予約で
            // 送り直せば Stripe が最初の応答を返す。
            return PaymentAttempt::failed($exception->messageKey, $reservation);
        }

        $reservation->stripe_payment_intent_id = $charge->paymentIntentId !== '' ? $charge->paymentIntentId : null;

        // **課金が成立も保留もしていない場合、IDを保存しない。** B-02（10章）の除外条件は
        // 「課金が残っている」ことの印であり、取り消し済みの PaymentIntent を含めると、
        // カードの拒否のたびに `expired` にならない行が積み上がる。返金にはメモリ上の
        // IDを使うため、取り消し・返金の判断には影響しない。
        if (! $charge->requiresAction() && ! $charge->isSettled($amount)) {
            return $this->undoCharge($reservation, $charge);
        }

        try {
            $reservation->save();
        } catch (Throwable) {
            // IDを保存できないと、返金の手がかり（`stripe_payment_intent_id`）が残らない。
            // 課金だけが残る状態を避けるため、この場で後始末する（メモリ上のIDで返金できる）。
            return $this->undoCharge($reservation, $charge);
        }

        if ($charge->requiresAction()) {
            // 3Dセキュア。ブラウザで認証を終えたのち `completeAuthentication()` が
            // PaymentIntent を取り直して検証する（クライアントの通知を信用しない。17.3-3）。
            return PaymentAttempt::requiresAuthentication($reservation, (string) $charge->clientSecret);
        }

        return $this->finalizeOrRefund($reservation, $screening, $breakdown, $holderKey, charged: true);
    }

    /**
     * 追加認証（3Dセキュア）の完了後に確定させる（8.2「決済結果の検証」）。
     *
     * **ブラウザからの「認証が完了した」という通知を信用しない。** 予約に記録した
     * PaymentIntent のIDでサーバーが取り直し、`status` と金額を確かめる（17.3-3）。
     */
    public function completeAuthentication(
        Reservation $reservation,
        Screening $screening,
        PriceBreakdown $breakdown,
        string $holderKey,
    ): PaymentAttempt {
        // 予約と上映回の対応を集約点でも確かめる（`reusable()` と対称。13.4.7）。
        // 食い違ったまま確定すると、`t_reservation_seats.screening_id` が親と不一致になる。
        if ($reservation->screening_id !== $screening->id) {
            return PaymentAttempt::failed('front.reservation.errors.payment_failed');
        }

        $charge = $this->settledCharge($reservation);

        if (! $charge instanceof CardCharge) {
            return $charge;
        }

        // **確定できる状態かを、金額の一致まで含めて確かめる。** 認証の間に別のタブで
        // 券種が変われば、課金した額と現在の内容が食い違う。その予約は成立させられない
        // ため、課金を取り消す（返金する）。
        if ($breakdown->total() !== $reservation->total_amount) {
            // 原因は座席の喪失ではなく内容の変更のため、専用の文言を使う。
            return $this->undoCharge($reservation, $charge, 'front.reservation.errors.contents_changed');
        }

        return $this->finalizeOrRefund($reservation, $screening, $breakdown, $holderKey, charged: true);
    }

    /**
     * 予約をキャンセルし、返金する（4.4）。
     *
     * **順序は「DBを確定させてから返金する」。** 逆にすると、返金が成立したのに
     * `cancelled` へ移せなかった場合に「返金済みだが座席は占有したまま `paid`」が残り、
     * 利用者からはキャンセルに失敗したように見えるため再実行を招く。DBを先に確定させれば、
     * 返金が失敗しても座席は再販に戻り、残るのは「返金の未了」という運用で追える状態
     * だけになる（`stripe_payment_intent_id` が在り `refunded_at` が null の行）。
     *
     * **外部との通信をトランザクションの内側に置かない**（`payAndConfirm()` と同じ。
     * 行ロックを保持したまま Stripe の応答を待たない）。
     *
     * 4.4 の条件はすべて**トランザクションの内側で**判定し直す。画面を開いてから
     * ボタンを押すまでに上映開始20分前を過ぎうるし、その間に入場されることもある。
     */
    public function cancel(Reservation $reservation): Cancellation
    {
        $cancelled = $this->releaseSeats($reservation);

        if (! $cancelled instanceof Reservation) {
            return $cancelled;
        }

        // 0円の予約は課金していないため返金するものが無い（4.5.2「決済のスキップ条件」）。
        // **返金に触れない文言を使う**（「全額返金いたします」と案内しない）。
        if ($cancelled->total_amount === 0) {
            return Cancellation::done($cancelled, 'front.cancel.done_no_refund');
        }

        if (! $this->refundOnce($cancelled)) {
            return Cancellation::refundPending($cancelled, 'front.cancel.refund_pending');
        }

        return Cancellation::done($cancelled, 'front.cancel.done');
    }

    /**
     * キャンセルの判定と、座席の解放（4.4 の処理1〜3）を単一トランザクションで行う。
     *
     * 条件を満たさない場合は `Cancellation`（`Rejected`）を返し、**何も変更しない**。
     * 成立した場合は読み直した予約を返す。
     *
     * **ロックの取得順は 上映回 → 予約 → 予約座席。** `SeatLockService::acquire()` と
     * A-09 が先頭で上映回行を掴むため（4.3.8）、同じ順に揃えて逆順を作らない。
     * あわせて、**トランザクションの最初の文をロック付きの読み取りにする**
     * （REPEATABLE READ のスナップショットが平文の SELECT で前倒しに確定し、
     * 以降の判定が古い値を読むため。4.3.8）。
     *
     * 無料鑑賞券は `t_reservations.active_free_ticket_id`（生成列）が `status` の変更に
     * 追随して解除されるため、明示的な更新を要しない（6.1.2「無料鑑賞券の使用状態の
     * 管理方式」。4.4 の処理3）。
     */
    private function releaseSeats(Reservation $reservation): Cancellation|Reservation
    {
        try {
            return $this->releaseSeatsTransaction($reservation);
        } catch (Throwable $exception) {
            // **ロック待ちの超過・デッドラインを顧客の 500 にしない。** 先頭で上映回行を
            // 掴むため、`SeatLockService::acquire()` や A-09 との競合は通常運用で起こりうる
            // （4.3.8）。**課金には触れていない段階**であり、ロールバックすれば何も変わって
            // いないため、承れなかったものとして案内して再実行を促せば足りる。
            Log::warning('Reservation could not be cancelled.', [
                'exception' => $exception::class,
                'reservation_id' => $reservation->id,
            ]);

            return Cancellation::rejected('front.cancel.errors.unavailable');
        }
    }

    /**
     * `releaseSeats()` の本体。**確定側（`finalize()`）と同じ回数だけ再試行する。**
     *
     * @throws Throwable ロック待ちの超過・デッドロック。呼び出し側が捕捉する
     */
    private function releaseSeatsTransaction(Reservation $reservation): Cancellation|Reservation
    {
        return DB::transaction(function () use ($reservation): Cancellation|Reservation {
            $now = Date::now();

            $screening = Screening::whereKey($reservation->screening_id)->lockForUpdate()->first();

            if ($screening === null) {
                // **到達しない防御。** `t_reservations.screening_id` が `restrictOnDelete`
                // であり（6.1追記表）、予約行が1件でも在れば状態を問わず削除できない。
                // `first()` が nullable を返す以上、握り潰さず拒否として返す。
                return Cancellation::rejected('front.cancel.errors.unavailable');
            }

            $current = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($current === null || $current->status !== ReservationStatus::Paid) {
                // 既にキャンセル済み・期限切れ。二度押しと複数タブからの同時実行がここで
                // 止まる（返金の冪等キーに頼る前の歯止め）。
                return Cancellation::rejected('front.cancel.errors.not_cancellable');
            }

            // 4.4-5。入場済みの予約はキャンセルできない。
            if ($current->checked_in_at !== null) {
                return Cancellation::rejected('front.cancel.errors.checked_in');
            }

            // 4.4-1。期限の規則は `Screening` が持つ（導線の出し分けと同じものを使う）。
            if (! $screening->acceptsCancellationAt($now)) {
                return Cancellation::rejected('front.cancel.errors.deadline_passed');
            }

            // 4.4 の処理1。状態遷移列は直接代入する（6.1追記表。`$fillable` から外れている）。
            $current->status = ReservationStatus::Cancelled;
            $current->cancelled_at = $now;
            $current->save();

            // 4.4 の処理2。**親の `status` 変更と同一トランザクションで行う**（6.4.2）。
            // `released_at` が入ると生成列 `active_seat_id` が NULL になり、
            // `(screening_id, active_seat_id)` の一意制約から外れて再販できる。
            // 「占有中」の条件は `occupying()` スコープに寄せる（4.3.8「条件の集約」。
            // 境界条件が複数箇所に分かれていると片方だけの改定を許す）。
            ReservationSeat::where('reservation_id', $current->id)
                ->occupying()
                ->update(['released_at' => $now]);

            return $current;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * 期限切れの `pending` を `expired` にする（B-02、10章 / 4.3.19）。
     *
     * **`pending` は `t_reservation_seats` を持たない**（座席は確定時に作る。6.4.2）
     * ため、解放すべき座席は無い。座席ロックは B-01 が消す。ここで行うのは状態の
     * 整理だけである。
     *
     * **課金が残っていないことを確かめてから倒す。** 10章が「`stripe_payment_intent_id`
     * を持ち `refunded_at` が未設定の予約は対象外」としていたのは、その行が**課金が
     * 残っていることを示す唯一の手がかり**だからである（4.3.15）。ただし一律に除外すると、
     * 3Dセキュアを中断しただけの予約（課金は成立していない）が恒久的に残り、A-09 が
     * その上映回を編集できなくなる（旧12章 残課題35）。そこで**除外する代わりに
     * Stripe へ問い合わせ**、取り消せたものは手がかりごと消してから倒す。
     *
     * @param  int  $limit  1回の実行で処理する上限
     * @return PendingExpiry 無効化した件数と、課金が残っていて見送った件数
     */
    public function expirePending(int $limit = self::EXPIRE_PER_RUN_LIMIT): PendingExpiry
    {
        $expired = 0;
        $withCharge = 0;
        $failed = 0;

        foreach ($this->expirable($limit) as $candidate) {
            try {
                $chargedId = $candidate->stripe_payment_intent_id;

                // **Stripe への問い合わせはトランザクションの外で行う**（8.2 / 4.3.18）。
                if (! $this->hasNoRemainingCharge($candidate)) {
                    $withCharge++;

                    continue;
                }

                // 取り消せた場合のみ、そのIDを外す対象として渡す（`hasNoRemainingCharge()`
                // が候補のIDを null にした場合に限る）。返金済み・IDが無い場合は触らない。
                $cancelledId = $candidate->stripe_payment_intent_id === null ? $chargedId : null;

                if ($this->expireOne($candidate, $cancelledId)) {
                    $expired++;
                }
            } catch (Throwable $exception) {
                // 1件の失敗で残りを止めない。次回の実行が拾い直す。**例外のクラス名だけを
                // 残す**（メッセージにはバインド値が載る。17.4.3 / 4.3.15）。
                $failed++;

                Log::warning('Pending reservation could not be expired.', [
                    'exception' => $exception::class,
                    'reservation_id' => $candidate->id,
                ]);
            }
        }

        return new PendingExpiry($expired, $withCharge, $failed);
    }

    /**
     * 1件を `expired` へ倒す（4.3.19）。倒した場合のみ true を返す。
     *
     * **予約行を `lockForUpdate()` で読み直し、条件を再判定してから書く。**
     * `expirable()` が先読みしてから Stripe の応答を待つ間に、その予約が確定
     * （`paid`）しうる。主キー指定の無条件な更新で書き戻すと、**確定を `expired` で
     * 上書きする**（座席は `t_reservation_seats` に残るのに、利用者の画面から予約が
     * 消え、キャンセル＝返金の導線も断たれる）。`finalize()` が同じ理由で予約を
     * 読み直しているのと同型である。
     *
     * **再判定の主眼は `status` である。** 予約の `expires_at` は使い回しの際に現在の
     * ロックへ引き直すため（`payAndConfirm()`）、読み直しても通常は同じ値を読む。
     * それでも条件に含めるのは、引き直しの保存に失敗した予約を倒さないためである。
     *
     * @param  string|null  $cancelledId  取り消せた PaymentIntent のID（外す対象）
     */
    private function expireOne(Reservation $candidate, ?string $cancelledId): bool
    {
        return DB::transaction(function () use ($candidate, $cancelledId): bool {
            $reservation = Reservation::query()->whereKey($candidate->id)->lockForUpdate()->first();

            if ($reservation === null || $reservation->status !== ReservationStatus::Pending) {
                return false;
            }

            if ($reservation->expires_at === null || $reservation->expires_at->greaterThan(Date::now())) {
                return false;
            }

            $reservation->status = ReservationStatus::Expired;
            // 終端に達した予約は期限を持たない（`paid` と同じ扱い）。
            $reservation->expires_at = null;

            // **取り消したIDと一致する場合だけ外す。** 先読みの後に利用者が再課金して
            // 新しい PaymentIntent を保存していることがあり、それを消すと**課金の
            // 手がかりを失う**（取り消したのは古いIDである。17.3-5）。
            if ($cancelledId !== null && $reservation->stripe_payment_intent_id === $cancelledId) {
                $reservation->stripe_payment_intent_id = null;
            }

            $reservation->save();

            return true;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * 無効化の対象となる予約（4.3.19）。
     *
     * `expires_at` は保持していたロックのうち最も早い期限に合わせてある
     * （`createPending()`）。その時点で座席は他の顧客へ渡るため、予約も無効化される。
     *
     * @return Collection<int, Reservation>
     */
    private function expirable(int $limit): Collection
    {
        return Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Date::now())
            // 古いものから片付ける（`expires_at` は作成順に並ぶとは限らない）。
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();
    }

    /**
     * この予約に課金が残っていないと確かめられるか（4.3.19）。
     *
     * | 状態 | 判定 |
     * |---|---|
     * | PaymentIntent のIDが無い | 残っていない（課金の要求を出していない、または応答を得られなかった。10章） |
     * | 返金済み（`refunded_at`） | 残っていない |
     * | Stripe 上で成立している（`succeeded`） | **残っている。** 倒さず手がかりとして残す（17.3-5 / 12章 残課題39） |
     * | 取り消し済み（`canceled`） | 残っていない。**再度 `cancel` を投げない**（下記） |
     * | 未確定で、取り消せた | 残っていない。**IDを外してから倒す**（手がかりに偽物を残さない） |
     * | 未確定だが取り消せない・問い合わせに失敗 | 判断しない。次回の実行に送る |
     *
     * **取り消し済みを先に見るのは、前回の実行が取り消しに成功した直後に保存へ失敗した
     * 場合に詰まらないようにするため。** その行は `pending` のままIDを持って残るが、
     * Stripe 側は既に `canceled` であり、再度 `cancel` を送るとエラーが返る。取り消せ
     * なかったものと区別できないと、**以後どの実行でも倒せない行になる**（4.3.19）。
     */
    private function hasNoRemainingCharge(Reservation $reservation): bool
    {
        $paymentIntentId = $reservation->stripe_payment_intent_id;

        if ($paymentIntentId === null || $reservation->refunded_at !== null) {
            return true;
        }

        try {
            $charge = $this->stripe->retrievePayment($paymentIntentId);
        } catch (StripeException) {
            // 成否が不明。**倒さない。** 課金が残っている可能性を消さずに残す。
            return false;
        }

        if ($charge->status === CardCharge::STATUS_SUCCEEDED) {
            return false;
        }

        if ($charge->status !== CardCharge::STATUS_CANCELED && ! $this->stripe->cancelPayment($paymentIntentId)) {
            return false;
        }

        // 取り消せた PaymentIntent のIDは外す（`undoCharge()` と同じ扱い）。課金は
        // 残っておらず、残すと「課金が残っている予約」の目印が偽物になる。
        $reservation->stripe_payment_intent_id = null;

        return true;
    }

    /**
     * 認証の途中で前提（座席・券種）が崩れた予約を後始末する（8.2）。
     *
     * **画面が確定できないと判断した場合でも、課金を放置しない。** 3Dセキュアの認証に
     * 時間がかかって座席ロックが切れた場合など、ブラウザからの通知を受けた時点で
     * 課金だけが成立している状態がありうる。
     */
    public function abandon(Reservation $reservation): PaymentAttempt
    {
        $charge = $this->settledCharge($reservation);

        if (! $charge instanceof CardCharge) {
            return $charge;
        }

        return $this->undoCharge($reservation, $charge);
    }

    /**
     * 予約に記録した PaymentIntent を再取得する（17.3-3）。
     *
     * 課金が成立している場合のみ `CardCharge` を返す。取得できない・成立していない
     * 場合は、そのまま画面へ返せる `PaymentAttempt` を返す。
     */
    private function settledCharge(Reservation $reservation): CardCharge|PaymentAttempt
    {
        $paymentIntentId = $reservation->stripe_payment_intent_id;

        if ($paymentIntentId === null) {
            // 課金の要求は出したが応答を得られなかった予約（通信失敗）。**捨てない。**
            // 作り直すと冪等キーが変わり、成立していた場合に二重課金になる（17.3-4）。
            return PaymentAttempt::failed('front.reservation.errors.payment_failed', $reservation);
        }

        try {
            $charge = $this->stripe->retrievePayment($paymentIntentId);
        } catch (StripeException $exception) {
            // 成否が不明な状態。**予約を捨てない**（作り直すと冪等キーが変わる）。
            return PaymentAttempt::failed($exception->messageKey, $reservation);
        }

        if (! $charge->isSettled($reservation->total_amount)) {
            return $this->undoCharge($reservation, $charge);
        }

        return $charge;
    }

    /**
     * 確定させられない課金を取り消す（8.2）。
     *
     * **`succeeded` は取り消せない。** Stripe が取り消せるのは未確定の PaymentIntent
     * だけであり、成立済みのものに `cancel` を呼んでも課金は残る。成立の有無で
     * 返金と取り消しを分ける（17.3-5）。
     */
    private function undoCharge(
        Reservation $reservation,
        CardCharge $charge,
        string $refundedMessageKey = 'front.reservation.errors.seats_taken',
    ): PaymentAttempt {
        if ($charge->status === CardCharge::STATUS_SUCCEEDED) {
            // 返金に失敗した場合はその旨の文言が優先される。
            return PaymentAttempt::seatsUnavailable($this->refundFailure($reservation) ?? $refundedMessageKey);
        }

        if ($charge->paymentIntentId !== '' && $this->stripe->cancelPayment($charge->paymentIntentId)) {
            // **取り消せた PaymentIntent のIDは予約から外す。** 課金は残っていないため、
            // B-02（10章）の除外条件（＝課金が残っている予約の目印）に残さない。
            // 3Dセキュアの中断は日常的に起こるため、放置すると `expired` にならない行が
            // 積み上がる。取り消せなかった場合は（保存済みであれば）IDを残し、追跡できる
            // ようにする。カードの拒否ではそもそも保存していない。
            //
            // 保存の成否は見ない。課金が残らない経路であり、失敗しても金銭上の不整合に
            // ならない（残るのは B-02 の除外条件に掛かる行が1件増えることだけ）。
            $reservation->stripe_payment_intent_id = null;
            $this->persist($reservation);
        }

        return PaymentAttempt::failed('front.reservation.errors.payment_failed');
    }

    /**
     * 課金の成立後に確定を試み、失敗した場合は返金する（8.2）。
     */
    private function finalizeOrRefund(
        Reservation $reservation,
        Screening $screening,
        PriceBreakdown $breakdown,
        string $holderKey,
        bool $charged,
    ): PaymentAttempt {
        try {
            $confirmed = $this->finalize($reservation, $screening, $breakdown, $holderKey);
        } catch (Throwable $exception) {
            // 原因を追えるよう、**例外の種別と予約IDだけ**を残す（4.3.14 が P-37 の実装時に
            // 検討するとしていた記録の範囲）。例外メッセージにはバインド値（入場コード等）が
            // 載るため、メッセージそのものは出さない（17.9-1 / 17.4.3）。
            Log::warning('Reservation could not be confirmed after payment.', [
                'exception' => $exception::class,
                'reservation_id' => $reservation->id,
            ]);

            // 6.4.2 の `(screening_id, active_seat_id)` による一意制約違反のほか、
            // **ロック待ちの超過・デッドロック（`QueryException`）や採番の枯渇も含めて
            // 捕捉する。** 課金が成立した後に例外をそのまま投げると、返金されないまま
            // 500 になる（17.3-5）。原因を問わず座席を確保できなかったものとして扱う。
            $confirmed = null;
        }

        if ($confirmed !== null) {
            // 確定した予約は**トランザクションの内側で読み直したインスタンス**を返す
            // （再試行に備えて読み直しているため、呼び出し元の変数とは別物になりうる）。
            return PaymentAttempt::confirmed($confirmed);
        }

        if (! $charged) {
            // 0円の予約。返金するものが無いため、返金に触れない文言を使う。
            return PaymentAttempt::seatsUnavailable('front.reservation.errors.seats_taken_no_payment');
        }

        return PaymentAttempt::seatsUnavailable(
            $this->refundFailure($reservation) ?? 'front.reservation.errors.seats_taken',
        );
    }

    /**
     * 返金する（8.2 / 17.3-5）。**失敗した場合のみ**文言のキーを返す。
     *
     * **返金は予約1件につき1回。** `refunded_at` が入っていれば再実行しない。
     * 失敗した場合は `refunded_at` を立てず、課金が残っている旨の案内へ倒す。
     * 予約には `stripe_payment_intent_id` が残るため、運用側から追跡できる。
     *
     * **`refunded_at` の保存に失敗した場合の二重返金を防ぐのは冪等キー**
     * （`refund:{予約ID}`）である。17.3-5 は `refunded_at` を主体に書いているが、
     * 記録が落ちた経路ではキーだけが歯止めになる。キーの構成を変える場合は注意すること。
     */
    private function refundFailure(Reservation $reservation): ?string
    {
        return $this->refundOnce($reservation) ? null : 'front.reservation.errors.refund_failed';
    }

    /**
     * 返金を**予約1件につき1回だけ**実行する（8.2 / 17.3-5）。返金できた場合と、
     * 既に返金済みで実行する必要が無かった場合に true を返す。
     *
     * 確定の失敗（`refundFailure()`）とキャンセル（`cancel()`）の双方がここを通る。
     * **金銭に触れる経路を1箇所に集約する**ため、文言の出し分けは呼び出し側が行い、
     * 本メソッドは成否だけを返す。
     *
     * **`refunded_at` の保存に失敗した場合の二重返金を防ぐのは冪等キー**
     * （`refund:{予約ID}`）である。17.3-5 は `refunded_at` を主体に書いているが、
     * 記録が落ちた経路ではキーだけが歯止めになる。キーの構成を変える場合は注意すること。
     */
    private function refundOnce(Reservation $reservation): bool
    {
        $paymentIntentId = $reservation->stripe_payment_intent_id;

        if ($paymentIntentId === null) {
            // 課金は成立しているのに返金先が分からない状態。返金できていない以上、
            // 「全額返金いたします」とは案内しない。
            return false;
        }

        if ($reservation->refunded_at !== null) {
            // 返金は予約1件につき1回（17.3-5）。既に返金済みなので失敗ではない。
            return true;
        }

        try {
            $this->stripe->refund($paymentIntentId, 'refund:'.$reservation->id);
        } catch (StripeException) {
            // **返金できていない。手がかりを残す。** 10章 B-02 の除外条件は
            // `stripe_payment_intent_id` が保存されていることを前提とするため、
            // まだ未保存なら保存しておく。あわせてログにも残す（PaymentIntent のIDは
            // 秘匿情報ではない。17.4.3 が禁じるのは連絡先・各種コード・キー）。
            $this->persist($reservation);

            Log::warning('Refund failed after a successful charge.', [
                'reservation_id' => $reservation->id,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return false;
        }

        $reservation->refunded_at = Date::now();

        if (! $this->persist($reservation)) {
            // 返金そのものは成立している。記録に失敗しただけなので利用者には返金済みと
            // 案内し、追跡できるよう残す。
            Log::warning('Refund succeeded but could not be recorded.', [
                'reservation_id' => $reservation->id,
                'payment_intent_id' => $paymentIntentId,
            ]);
        }

        return true;
    }

    /**
     * 予約確定トランザクション（8.2 手順1〜4）。
     *
     * 確定できた場合のみ**確定した予約**を返す（読み直したインスタンスであり、
     * 引数の `$reservation` とは別物になりうる）。確定できなければ null を返す。
     *
     * **ロックの取得順は 上映回 → 座席 → 予約**（4.3.8 / 4.3.15）。`SeatLockService::acquire()` と
     * 同じ順序に揃え、逆順を作らない。**トランザクションの最初の文をロック付きの
     * 読み取りにする**（REPEATABLE READ のスナップショットが平文の SELECT で前倒しに
     * 確定し、以降の判定が古い値を読むため。4.3.8）。
     *
     * @throws Throwable 同時実行が同じ座席を先に確定させた場合（一意制約違反）、
     *                   ロック待ちの超過・デッドロック、採番の枯渇。呼び出し側が
     *                   捕捉して返金する（`finalizeOrRefund()`）
     */
    private function finalize(
        Reservation $reservation,
        Screening $screening,
        PriceBreakdown $breakdown,
        string $holderKey,
    ): ?Reservation {
        return DB::transaction(function () use ($reservation, $screening, $breakdown, $holderKey): ?Reservation {
            $lockedScreening = Screening::query()->whereKey($screening->id)->lockForUpdate()->first();

            if ($lockedScreening === null) {
                // A-09 が削除した上映回。座席も金額も意味を失う。
                return null;
            }

            $seatIds = array_map(fn (SeatPrice $seat): int => $seat->seatId, $breakdown->seats);
            sort($seatIds);

            $seats = Seat::query()->whereIn('id', $seatIds)->orderBy('id')->lockForUpdate()->get();

            // **予約は必ず読み直す**（取得順は 上映回 → 座席 → 予約）。
            //
            // 理由は2つある。（1）デッドロックで再試行された場合、データベースは
            // ロールバックされてもメモリ上のモデルは巻き戻らない。前の試行で
            // `status = paid` を代入済みのインスタンスを使い回すと、2回目は dirty に
            // ならず UPDATE から落ち、`pending` のまま確定したことになる。
            // （2）同一の予約に対する確定が並走した場合に、既に確定済みかを判断できる。
            //
            // **座席・ロックの検証より前に判定する。** 先行が確定を終えているなら座席
            // ロックは削除済みであり、検証を先に行うと必ず「ロックの喪失」として
            // 返金経路へ落ちる（＝有効な予約の代金が返る）。
            $lockedReservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();

            if ($lockedReservation === null) {
                return null;
            }

            if ($lockedReservation->status === ReservationStatus::Paid) {
                // 既に確定している予約は、確定成功として扱う（冪等）。
                //
                // **この安全性は冪等キーが `reservation:{予約ID}` であることに依存する。**
                // 同一予約への2本の確定は必ず同じ PaymentIntent を指すため、「既に `paid`」は
                // 「自分の課金で確定済み」を意味する。キーの構成を変える場合は本分岐も見直すこと。
                return $lockedReservation;
            }

            if ($lockedReservation->status !== ReservationStatus::Pending) {
                // `expired` / `cancelled` は終端（4.3.3）。確定させない。
                return null;
            }

            // 13.4.7: 座席が対象上映回のシアターに属することはDB制約で表現できないため、
            // 確定前に検証する。`SeatLockService::acquire()` の条件2と同じ観点。
            if ($seats->count() !== count($seatIds)) {
                return null;
            }

            foreach ($seats as $seat) {
                if ($seat->theater_id !== $lockedScreening->theater_id) {
                    return null;
                }
            }

            // 8.2 手順1: 対象ロックが自分の `holder_key` のもので期限内であること（6.4.2）。
            $heldSeatIds = SeatLock::query()
                ->where('screening_id', $lockedScreening->id)
                ->whereIn('seat_id', $seatIds)
                ->where('holder_key', $holderKey)
                ->active()
                ->pluck('seat_id')
                ->all();

            if (count($heldSeatIds) !== count($seatIds)) {
                return null;
            }

            // 手順2: `paid` へ更新し、入場コードを採番する（4.6.2-1）。
            $lockedReservation->status = ReservationStatus::Paid;
            $lockedReservation->entry_code = $this->newEntryCode();
            // `pending` の期限切れ（B-02）の対象から外す。確定後は期限を持たない。
            $lockedReservation->expires_at = null;
            $lockedReservation->save();

            // 手順3: 席ごとの確定額を保存する（6.5.5）。`screening_id` は 6.4.2 の
            // ユニーク制約のために冗長に持つ。
            $now = Date::now();

            ReservationSeat::query()->insert(array_map(fn (SeatPrice $seat): array => [
                'reservation_id' => $lockedReservation->id,
                'screening_id' => $lockedScreening->id,
                'seat_id' => $seat->seatId,
                'ticket_type_id' => $seat->ticketTypeId,
                'amount' => $seat->amount(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $breakdown->seats));

            // 手順4: 確定した座席のロックを削除する（6.4.1-6）。以降の排他は
            // `t_reservation_seats` のユニーク制約が担う。**書き込みは
            // `SeatLockService` に集約する**（13.4.6）。
            $this->locks->releaseHeld($lockedScreening, $seatIds, $holderKey);

            // デッドロック時は Laravel が再試行する（判定も書き込みもやり直すため、
            // 部分的に適用された状態は残らない）。
            return $lockedReservation;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * 予約を保存する。失敗しても例外にしない（金銭の後始末を止めないため）。
     *
     * 課金・返金の直後の保存は、失敗しても Stripe 側の状態を巻き戻せない。例外を
     * そのまま投げると、返金済みであることを利用者に伝えられないまま500になる。
     */
    private function persist(Reservation $reservation): bool
    {
        try {
            $reservation->save();
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * 直前の試行で作った予約を使い回せるか。
     *
     * **上映回と支払金額が一致する場合に限る。** 別の内容で作られた予約を使い回すと、
     * `t_reservations.total_amount` と `t_reservation_seats.amount` の合計が
     * 食い違う（6.5.5）。一致しない場合は使わず、新しい予約を作る。
     */
    private function reusable(?Reservation $pending, Screening $screening, int $amount): ?Reservation
    {
        if ($pending === null) {
            return null;
        }

        // **返金済み・確定済みの予約は使い回さない。** 冪等キーの再生で課金済みとして
        // 確定すると、返金された予約がそのまま成立する（＝無償で座席を取得できる）。
        // 画面側（`Confirm::pendingReservation()`）も絞り込むが、確定の集約点である
        // 本サービス側でも閉じておく（13.4.7）。
        return $pending->screening_id === $screening->id
            && $pending->total_amount === $amount
            && $pending->refunded_at === null
            && $pending->status === ReservationStatus::Pending
                ? $pending
                : null;
    }

    /**
     * 課金前の `pending` 予約を作る（4.3.3）。
     *
     * `expires_at` は保持中のロックのうち最も早い期限に合わせる。ロックが切れた時点で
     * 座席は他の顧客へ渡るため、予約もそこで無効化されるべきである（B-02、10章）。
     */
    private function createPending(
        Screening $screening,
        PriceBreakdown $breakdown,
        Purchaser $purchaser,
        CarbonImmutable $expiresAt,
    ): Reservation {
        return Reservation::create([
            ...$purchaser->attributes(),
            'reservation_no' => $this->newReservationNo(),
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Pending->value,
            'total_amount' => $breakdown->total(),
            // 無料鑑賞券の選択（7.10-4）が未実装のため、現状は常に null（12章 残課題31）。
            'free_ticket_id' => $breakdown->freeTicketId,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 予約番号（4.3.5）。8桁の数字。重複した場合は採り直す。
     */
    private function newReservationNo(): string
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $candidate = str_pad(
                (string) random_int(0, (10 ** self::RESERVATION_NO_DIGITS) - 1),
                self::RESERVATION_NO_DIGITS,
                '0',
                STR_PAD_LEFT,
            );

            if (! Reservation::query()->where('reservation_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        // 採番できない状態（桁数に対して予約が多すぎる）はデータ量の設計の問題であり、
        // 課金前に止める。黙って続けると一意制約違反が課金後に出る。
        throw new RuntimeException('Failed to allocate a reservation number.');
    }

    /**
     * 入場コード（4.6.2-1）。`Str::random(32)` の英数字列。
     *
     * **連番・予約IDを使わない**（他者のコードを推測可能になるため。4.6.2 の【根拠】）。
     */
    private function newEntryCode(): string
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $candidate = Str::random(self::ENTRY_CODE_LENGTH);

            if (! Reservation::query()->where('entry_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Failed to allocate an entry code.');
    }
}
