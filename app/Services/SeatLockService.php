<?php

namespace App\Services;

use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * 座席の一時ロック（6.4.1）の取得・延長・解放・移譲を集約する（13.4.6）。
 * `t_seat_locks` への直接操作を各所に書かない。
 *
 * **排他の要**: 取得可否は対象の上映回行・座席行を `lockForUpdate()` した内側で判定する。
 * あわせて `(screening_id, seat_id)` のユニーク制約を二重取得の最終防波堤として残し
 * （6.4.1-1）、制約違反は例外ではなく取得失敗（false）として扱う。
 *
 * **ロックの取得順**: 上映回（`t_screenings`）→ 座席（`m_seats`）。管理画面の順序
 * （映画 → 編成 → シアター、4.8.6追記表）の後ろに continue する形であり、逆順を作らない。
 * A-09（上映回の削除・更新）が同じ上映回行を、A-04（座席の使用可否切替）が同じ座席行を
 * 掴むため、双方と判定・書き込みが直列化される（4.3.8。旧12章 残課題13 の解消）。
 *
 * 編成行（`t_bookings`）は掴まない。編成の1行は「館 × 作品 × 規格」の上映期間全体を
 * 表すため、掴むと同一作品の全上映回・全顧客の座席選択が1行を奪い合い、A-08 の編集とも
 * 競合してロック待ちが顧客側の 500 として現れる。上映回行まで絞れば同じ直列化が成立する。
 */
class SeatLockService
{
    /** ロックの有効期限（分、6.4.1-3）。 */
    public const int LOCK_MINUTES = 10;

    /** 決済画面への遷移時に延長する有効期限（分、6.4.1-4）。 */
    public const int PAYMENT_LOCK_MINUTES = 15;

    /** 1利用者が1上映回で保持できる座席数の上限（4.3.4 / 17.8-2）。 */
    public const int MAX_SEATS_PER_HOLDER = 8;

    /**
     * 座席のロックを取得する。取得できた場合のみ true を返す。
     *
     * 同一利用者が既に保持している座席への再取得は、有効期限を引き直して true を返す
     * （座席表の再クリックによる取り直しを失敗にしない）。
     *
     * 以下をすべて満たす場合にのみ取得できる。判定はロックの内側で行い、
     * 判定と書き込みの間に他の操作が割り込む経路を作らない。
     *
     * | # | 条件 | 参照 |
     * |---|---|---|
     * | 1 | 上映回が存在する（A-09 が削除していない） | 6.2 制約1 / 4.3.8 |
     * | 2 | 座席が対象上映回のシアターに属する | 13.4.7 と同型 |
     * | 3 | 座席が使用可能（`is_available`。A-04 が無効化していない） | 6.2 制約2 / 4.3.8 |
     * | 4 | 販売期間内（上映日の3日前 0:00 〜 上映開始時刻） | 4.3.1 |
     * | 5 | 確定済みの予約が座席を占有していない | 6.4.1-6 / 6.4.2 |
     * | 6 | 同一利用者が他の上映回のロックを保持していない | 4.3.4 |
     * | 7 | 同一利用者の保持座席が8席未満 | 4.3.4 / 17.8-2 |
     * | 8 | 他者の有効なロックが無い | 6.4.1-1 |
     */
    public function acquire(Screening $screening, Seat $seat, string $holderKey): bool
    {
        return DB::transaction(function () use ($screening, $seat, $holderKey): bool {
            $now = Date::now();

            /*
             * **トランザクションの最初の文は必ずロック付きの読み取りにすること。**
             * InnoDB の REPEATABLE READ では、スナップショット（read view）は最初の
             * 「ロックを伴わない」読み取りで確定する。先頭に平文の SELECT を1文でも置くと
             * 視点が行ロックの取得前に前倒しされ、条件5〜8 が古い値を読む（テストでは
             * 検出できない）。ロック付きの読み取りは常に最新のコミット済みの値を読む。
             */

            // 条件1。A-09 の削除・更新は同じ上映回行を掴んだうえで行われるため、
            // ここでの再読み込みは「削除済み（false）」か「以後は変更されない」に確定する。
            $current = Screening::whereKey($screening->id)->lockForUpdate()->first();

            if ($current === null) {
                return false;
            }

            // 条件4。販売期間の規則は Screening が持つ（表示側の判定と同じものを使う）。
            if (! $current->isOnSale($now)) {
                return false;
            }

            // 条件3。A-04 は座席行のロック下で `is_available` を更新するため、
            // ここでの確認と後続の書き込みの間に無効化が割り込まない（6.2 制約2）。
            $target = Seat::whereKey($seat->id)->lockForUpdate()->first();

            if ($target === null || ! $target->is_available) {
                return false;
            }

            // 条件2。座席が別シアターに属する場合、その上映回では販売できない。
            if ($target->theater_id !== $current->theater_id) {
                return false;
            }

            // 条件5。決済が完了した座席はロック行を持たない（6.4.1-6 が確定時に削除する）ため、
            // ユニーク制約では防げない。ここで除外しないと、販売済みの座席を選べてしまい、
            // 課金成立後に 6.4.2 の一意制約違反として失敗する（返金を伴う経路）。
            $sold = ReservationSeat::where('screening_id', $current->id)
                ->where('seat_id', $target->id)
                ->occupying()
                ->exists();

            if ($sold) {
                return false;
            }

            // ロック行そのものも掴む。掴まないと、読んでから書き戻すまでの間に
            // `release()` / `releaseAll()` / `transfer()` や B-01（10章）の削除が割り込み、
            // 「true を返したのにロック行が存在しない」状態を作る（保持済みとして描画された
            // 座席を他者が取得でき、8.2 手順1 の検証失敗＝課金後の返金経路に落ちる）。
            $existing = SeatLock::where('screening_id', $current->id)
                ->where('seat_id', $target->id)
                ->lockForUpdate()
                ->first();

            // 条件8。他者が有効なロックを保持している。
            if ($existing !== null && $existing->holder_key !== $holderKey && $existing->expires_at->isAfter($now)) {
                return false;
            }

            if (! $this->withinHolderLimit($current, $target, $holderKey, $now)) {
                return false;
            }

            return $this->write($current, $target, $holderKey, $now, $existing);
        });
    }

    /**
     * 保持中のロックを個別に解放する（座席表での選択解除、7.6.2）。
     * 他者のロックは解放できない（17.8-4）。
     */
    public function release(Screening $screening, Seat $seat, string $holderKey): void
    {
        SeatLock::where('screening_id', $screening->id)
            ->where('seat_id', $seat->id)
            ->where('holder_key', $holderKey)
            ->delete();
    }

    /**
     * 保持中のロックの有効期限を延長する（決済画面への遷移時に15分、6.4.1-4）。
     * 期限切れのロックは延長しない（B-01 による解放の対象として残す）。
     */
    public function extend(string $holderKey, int $minutes): void
    {
        $now = Date::now();

        SeatLock::where('holder_key', $holderKey)
            ->active($now)
            ->update(['expires_at' => $now->addMinutes($minutes)]);
    }

    /**
     * 保持中のロックをすべて解放する。
     */
    public function releaseAll(string $holderKey): void
    {
        SeatLock::where('holder_key', $holderKey)->delete();
    }

    /**
     * ロックの保持者を移す（ログイン・会員登録の完了時、13.4.6 / 7.8）。
     * `session:{id}` から `user:{id}` への引き継ぎに用いる。
     *
     * 移す対象がある場合に限り、移譲先が既に保持しているロックを解放する。会員が別の
     * セッションで取得したロックが残っていると、移譲後に「1上映回・8席」（4.3.4）を
     * 超えるため。**移譲先が別のブラウザで決済中だった場合、その座席は失われる**（4.3.8）。
     *
     * **移譲元に有効なロックが無い場合は移譲先に触れない。** 本メソッドは座席選択を伴わない
     * 通常のログインでも呼ばれるため（13.4.6）、無条件に削除すると、別タブで座席を選択中の
     * 会員がログインし直しただけで選択が無言で消える。
     *
     * 移すのは有効なロックのみとし、移譲元の期限切れの行は削除する。期限切れの行を移すと、
     * 移譲先が「有効なロックを失い、期限切れの行だけを持つ」状態になりうる。
     */
    public function transfer(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        DB::transaction(function () use ($from, $to): void {
            if (! SeatLock::where('holder_key', $from)->active()->exists()) {
                SeatLock::where('holder_key', $from)->delete();

                return;
            }

            SeatLock::where('holder_key', $to)->delete();
            SeatLock::where('holder_key', $from)->active()->update(['holder_key' => $to]);
            SeatLock::where('holder_key', $from)->delete();
        });
    }

    /**
     * 利用者が対象の上映回で保持している座席IDを返す（座席表の再描画、7.6.2）。
     *
     * @return array<int, int>
     */
    public function heldSeatIds(Screening $screening, string $holderKey): array
    {
        return SeatLock::where('screening_id', $screening->id)
            ->where('holder_key', $holderKey)
            ->active()
            ->orderBy('seat_id')
            ->pluck('seat_id')
            ->all();
    }

    /**
     * 対象の上映回以外で保持しているロックを解放する（4.3.4「別の上映回の座席選択に
     * 移行した時点で、前の上映回のロックを解放する」）。座席選択画面（P-31）への
     * 入場時に呼ぶ。`acquire()` は他の上映回のロックが残っていると取得を拒むため、
     * 画面側でこの解放を先に行う。
     */
    public function releaseOtherScreenings(Screening $screening, string $holderKey): void
    {
        SeatLock::where('holder_key', $holderKey)
            ->where('screening_id', '!=', $screening->id)
            ->delete();
    }

    /**
     * 条件6・7（1上映回・8席まで、4.3.4）を判定する。
     *
     * 判定は対象の上映回行のロック下で行うため、同一上映回に対する同時取得では正しく働く。
     * ただし**別々の上映回への同時リクエスト**は共通の行を掴まないため、同一利用者が
     * 2上映回のロックを保持する状態を作りうる（4.3.8。17.8-2 はアプリケーション側の制限）。
     * 既に自分が保持している座席の取り直しは、席数を増やさないため上限の対象外とする。
     */
    private function withinHolderLimit(Screening $screening, Seat $seat, string $holderKey, CarbonImmutable $now): bool
    {
        $held = SeatLock::where('holder_key', $holderKey)->active($now)->get(['screening_id', 'seat_id']);

        if ($held->contains(fn (SeatLock $lock): bool => $lock->screening_id !== $screening->id)) {
            return false;
        }

        if ($held->contains(fn (SeatLock $lock): bool => $lock->seat_id === $seat->id)) {
            return true;
        }

        return $held->count() < self::MAX_SEATS_PER_HOLDER;
    }

    /**
     * ロック行を書き込む。既存の行（期限切れ、または自分が保持しているもの）があれば
     * 保持者と期限を引き直し、無ければ挿入する。
     *
     * 挿入時のユニーク制約違反は取得失敗（false）として扱う。条件8は上映回行のロック下で
     * 判定済みだが、**ユニーク制約が最終防波堤である**という 6.4.1-1 の設計をコード上でも
     * 維持する（アプリケーション側の判定が将来壊れても二重取得が成立しない）。
     *
     * 影響行数では判定しない。MariaDB の `UPDATE` が返すのは一致行数ではなく変更行数で
     * あり（`PDO::MYSQL_ATTR_FOUND_ROWS` は無効）、`expires_at` が秒精度のため、
     * 同一利用者が同じ秒に再取得すると値が変わらず0件となる。座席表のダブルクリックや
     * 通信の再送で「自分が保持している座席の取得に失敗する」ことになる。
     */
    private function write(Screening $screening, Seat $seat, string $holderKey, CarbonImmutable $now, ?SeatLock $existing): bool
    {
        $expiresAt = $now->addMinutes(self::LOCK_MINUTES);

        if ($existing !== null) {
            $existing->holder_key = $holderKey;
            $existing->expires_at = $expiresAt;
            $existing->save();

            return true;
        }

        try {
            SeatLock::create([
                'screening_id' => $screening->id,
                'seat_id' => $seat->id,
                'holder_key' => $holderKey,
                'expires_at' => $expiresAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            // 他者が有効なロックを保持している。取得できないだけであり異常ではない。
            return false;
        }

        return true;
    }
}
