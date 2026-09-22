<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\FreeTicket;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\Stamp;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * スタンプの付与と無料鑑賞券への交換（4.5.1 / 10章 B-03）。
 *
 * **1回の実行は「付与」と「交換」の2段で進む。**
 *
 * | # | 段 | 内容 |
 * |---|---|---|
 * | 1 | 付与 | 上映開始を過ぎた決済済み予約のうち未付与のものに `t_stamps` を1行作る |
 * | 2 | 交換 | 未交換が `FreeTicket::STAMPS_PER_TICKET` に達した会員へ券を発行する |
 *
 * **2段は独立している。** 交換の対象は「今回付与した会員」ではなく**毎回数え直す**ため、
 * 前回の実行が途中で落ちて取り残された会員も次の実行で拾える（`awaitingExchange()`）。
 *
 * **多重実行に耐える**（batch スキル / 10章）。付与は `t_stamps.reservation_id` の一意
 * 制約が二重付与を止め、交換は会員行を `lockForUpdate()` してから数えるため、同時に
 * 走っても券が余分に出ない。
 */
class StampService
{
    /**
     * 1回の実行で付与するスタンプの上限（10章 B-03）。
     *
     * **遡及の下限は設けない**（未付与の予約は必ずいつか拾う）。代わりに1回を打ち切り、
     * 10分ごとの実行で順に消化する。運用開始時や `migrate:fresh --seed` の直後は
     * 過去分が数万件まとめて対象になり、上限が無いと1回の実行がエックスサーバーの
     * 実行時間内に収まらないため（9.3追記表のとおりシーダーは直近14日で約4.8万件を作る）。
     */
    public const int PER_RUN_LIMIT = 1000;

    /** 無料鑑賞券コードの採番の試行回数（`ReservationService` の入場コードと同じ回数）。 */
    private const int CODE_ATTEMPTS = 10;

    /** 無料鑑賞券コードの桁数（13.3。`Str::upper(Str::random(12))`）。 */
    private const int CODE_LENGTH = 12;

    /**
     * スタンプを付与し、たまった会員へ無料鑑賞券を発行する（10章 B-03）。
     *
     * @param  int  $limit  1回の実行で付与する上限
     */
    public function grant(int $limit = self::PER_RUN_LIMIT): StampGrant
    {
        $granted = 0;

        foreach ($this->awaitingStamp($limit) as $reservation) {
            if ($this->grantOne($reservation)) {
                $granted++;
            }
        }

        $issued = 0;
        $failed = 0;

        foreach ($this->awaitingExchange() as $userId) {
            try {
                $issued += $this->exchange($userId);
            } catch (Throwable $exception) {
                // **1人の失敗で残りを止めない。** 付与は済んでおり、残りの会員の交換を
                // 諦める理由が無い。失敗した会員は次回の実行が拾い直す（対象は毎回
                // 数え直すため。`awaitingExchange()`）。
                $failed++;

                // **例外のクラス名だけを残す**（17.4.3 / 4.3.15 と同じ扱い）。`QueryException`
                // のメッセージにはバインド値を埋めた SQL が載るため、そのまま出すと
                // **無料鑑賞券コードがログに残る**（17.4.3 が明示的に禁じている）。
                Log::error('Free ticket could not be issued.', [
                    'exception' => $exception::class,
                    'user_id' => $userId,
                ]);
            }
        }

        return new StampGrant($granted, $issued, $failed);
    }

    /**
     * 付与の対象となる予約（4.5.1-4・5）。
     *
     * | 条件 | 根拠 |
     * |---|---|
     * | 上映開始時刻を経過している | 4.5.1-5。入場実績は問わない（当日の急用による不来場を考慮する） |
     * | `paid` である | 4.5.1-5「決済済み予約」。`cancelled` は対象外 |
     * | 会員の予約である | 4.5.1 はスタンプを会員特典とする。非会員は `user_id` が null |
     * | 無料鑑賞券を使っていない | 4.5.1-4。判定は生成列 `active_free_ticket_id` による（4.5.5） |
     * | まだスタンプが無い | 二重付与を防ぐ。最後の歯止めは `t_stamps.reservation_id` の一意制約 |
     *
     * **並びは予約IDの昇順とする。** 上映開始の順に並べるには相関サブクエリでの
     * 並べ替えが要り、上限で切り出すために毎回全件を並べ替えることになる。予約IDは
     * 予約した順であり、打ち切りの基準として足りる（**どの予約から付けるかは、券の
     * 発行枚数を変えない**。5個ごとに1枚という規則は順序に依らない）。
     *
     * @return Collection<int, Reservation>
     */
    private function awaitingStamp(int $limit): Collection
    {
        return Reservation::query()
            ->select(['id', 'user_id'])
            ->where('status', ReservationStatus::Paid)
            ->whereNotNull('user_id')
            ->whereNull('active_free_ticket_id')
            ->whereDoesntHave('stamp')
            ->whereIn('screening_id', Screening::query()->where('starts_at', '<=', Date::now())->select('id'))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 1件にスタンプを付ける。既に付いていれば false を返す。
     *
     * **一意制約違反を握る。** 多重実行の際にここで衝突するのは想定内であり（10章）、
     * 実行を止める理由にならない。
     */
    private function grantOne(Reservation $reservation): bool
    {
        try {
            Stamp::create([
                'user_id' => $reservation->user_id,
                'reservation_id' => $reservation->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * 交換の対象となる会員（4.5.1-2）。
     *
     * **未交換のスタンプが規定数に達している会員を、毎回数え直して拾う。** 「今回
     * 付与した会員だけ」に絞る案は採らない。付与と交換の間で実行が落ちた会員が
     * 取り残され、**マイページが「まもなく無料鑑賞券を発行いたします」を出したまま
     * 止まる**（7.14 / `front.mypage.stamp.ready`）。次にその会員が鑑賞するまで
     * 解消せず、気づく契機も無い。
     *
     * 数え直しの費用は集約1本で済む。**定常状態では**未交換行は会員あたり4行までで、
     * 該当は0件になる（初回の消化中と、交換に失敗した会員だけが一時的に増える）。
     *
     * @return array<int, int>
     */
    private function awaitingExchange(): array
    {
        /** @var array<int, int> $userIds */
        $userIds = Stamp::query()
            ->select('user_id')
            ->whereNull('free_ticket_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) >= ?', [FreeTicket::STAMPS_PER_TICKET])
            ->pluck('user_id')
            ->all();

        return $userIds;
    }

    /**
     * たまったスタンプを無料鑑賞券へ交換する（4.5.1-2）。
     *
     * **会員行を `lockForUpdate()` してから数える。** スケジュール側の
     * `withoutOverlapping()` に加えて歯止めを置く（手動実行と重なりうる）。数えてから
     * 発行するまでの間に別のプロセスが同じスタンプを数えると、券が余分に出る。
     *
     * **再試行しない**（`ReservationService` の `TRANSACTION_ATTEMPTS` に倣わない）。
     * ロックを掴むのは B-03 だけであり競合はほぼ起きず、失敗しても**次回の実行が
     * 同じ会員を拾い直す**（交換の対象は毎回数え直すため）。利用者を待たせている
     * 場面ではないので、その場で粘る理由が無い（4.5.5）。
     *
     * **到達するたびに発行する**（10個たまっていれば2枚）。実行の間隔によって
     * 受け取れる枚数が変わらないようにする。
     *
     * @return int 発行した枚数
     */
    private function exchange(int $userId): int
    {
        return DB::transaction(function () use ($userId): int {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($user === null) {
                return 0;
            }

            $issued = 0;

            while ($user->unexchangedStamps()->count() >= FreeTicket::STAMPS_PER_TICKET) {
                if (! $this->issueTo($user)) {
                    // 消費できるスタンプが足りない。**数える式と消費する式がずれた場合の
                    // 歯止め**であり、通常は `while` の条件で先に抜ける。
                    break;
                }

                $issued++;
            }

            return $issued;
        });
    }

    /**
     * 無料鑑賞券を1枚発行し、消費したスタンプへ交換先を記録する（4.5.1-2 / 4.5.2-4）。
     *
     * **スタンプの行は消さない。** 「スタンプ数を0にリセット」は交換先（`free_ticket_id`）
     * の記録で表す（4.5.3。いつ何と交換したかを追える）。消費するのは**古い順**とする。
     *
     * @return bool 消費するスタンプがあり、交換が成立したか
     */
    private function issueTo(User $user): bool
    {
        $stampIds = $user->unexchangedStamps()
            ->orderBy('id')
            ->limit(FreeTicket::STAMPS_PER_TICKET)
            ->pluck('id');

        if ($stampIds->count() < FreeTicket::STAMPS_PER_TICKET) {
            return false;
        }

        $issuedAt = Date::now();

        $ticket = FreeTicket::create([
            'user_id' => $user->id,
            'code' => $this->newCode(),
            'issued_at' => $issuedAt,
            // 4.5.2-4。有効期限は発行から1年。
            'expires_at' => $issuedAt->addYear(),
        ]);

        $marked = Stamp::query()->whereIn('id', $stampIds)->update(['free_ticket_id' => $ticket->id]);

        // **印が付かなければ券だけが残る。** 券は先に作るため、ここで黙って続けると
        // 「交換していないのに券が増える」状態になる。トランザクションごと巻き戻し、
        // 会員単位の失敗として次回へ送る（`grant()` が握る。4.5.5）。
        if ($marked !== $stampIds->count()) {
            throw new RuntimeException('Stamps could not be marked as exchanged.');
        }

        return true;
    }

    /**
     * 無料鑑賞券コード（13.3。`Str::upper(Str::random(12))`）。
     *
     * 採番できない場合は例外を投げる。**`grant()` がこれを会員単位で握るため実行は
     * 止まらず、その会員だけが次回送りになる**（4.5.5「交換の失敗」。顧客の応答を
     * 500 にする `ReservationService::newEntryCode()` とはここが異なる）。失敗が続けば
     * `StampGrant::$failed` とログに毎回現れる。
     *
     * 重複の確認はトランザクションのスナップショットを読むため、同時実行の最後の
     * 守りは `t_free_tickets.code` の一意制約である。
     */
    private function newCode(): string
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $candidate = Str::upper(Str::random(self::CODE_LENGTH));

            if (! FreeTicket::query()->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Failed to allocate a free ticket code.');
    }
}
