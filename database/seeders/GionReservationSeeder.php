<?php

namespace Database\Seeders;

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Cinema;
use App\Models\FreeTicket;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\Stamp;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 祇園ムビの予約データを手動投入する（docs/design.md 9.1「予約データ」）。
 *
 * 9.1は祇園ムビの予約データを「動作確認用に手動投入」としており、
 * `ReservationSeeder`（他6館）とは異なり網羅的な件数は狙わない。
 * かわりに、4.3.3の全ステータス・4.5（スタンプ・無料鑑賞券）・4.4（キャンセル）・
 * 4.6（入場）といった、`ReservationSeeder` では意図的に再現していない状態を
 * 一通り手元で確認できるよう、少数の具体例のみを作成する。9.3-2（一括挿入）は
 * 件数が少なく非fillableの状態遷移列を直接代入する必要があるため適用しない。
 *
 * 会員側の例はすべて `SeedConfig::TEST_MEMBER_EMAIL`（開発時にログインする既定
 * アカウント）に紐づける。冪等性は祇園ムビの予約が1件でも存在するかで判定する
 * （`ReservationSeeder` と異なり時間経過による増分実行を想定しない固定データのため）。
 * 前提データ（会員・券種・上映回）が不足する場合はコンソールに警告を出したうえで
 * 何も投入しない。全体を1トランザクションに包み、途中で例外が出た場合に
 * 冪等性ガードが不完全なデータのまま固定化されないようにする。
 *
 * `pending` の座席ロック（`t_seat_locks`）は投入しない。`SeatLockService`（13.4.6）が
 * 未実装で直接操作は規約に反すること、ロックはB-01により10分程度で削除される
 * 短命なデータであることから、投入する実益が乏しいため見送った。
 */
class GionReservationSeeder extends Seeder
{
    /** @var array<int, array<int, bool>> screeningId => [seatId => true]（このシーダー実行内での座席の重複割当防止） */
    private array $usedSeatIdsByScreening = [];

    public function run(): void
    {
        $gion = Cinema::where('slug', SeedConfig::GION_SLUG)->first();

        if ($gion === null) {
            $this->command->warn('祇園ムビ（slug=gion）が存在しないため GionReservationSeeder をスキップしました。');

            return;
        }

        if (Reservation::whereHas('screening.theater.cinema', fn ($q) => $q->where('slug', SeedConfig::GION_SLUG))->exists()) {
            return;
        }

        $member = User::where('email', SeedConfig::TEST_MEMBER_EMAIL)->first();
        $ticketType = TicketType::where('name', SeedConfig::TICKET_TYPE_ADULT)->first();

        if ($member === null || $ticketType === null) {
            $this->command->warn('会員（'.SeedConfig::TEST_MEMBER_EMAIL.'）または券種マスタが未投入のため GionReservationSeeder をスキップしました。');

            return;
        }

        $theaterIds = $gion->theaters()->pluck('id');

        // starts_at の新しい順に取得する。[0] を無料鑑賞券使用の例に、
        // [1..STAMPS_PER_FREE_TICKET] をスタンプ交換用の来場に使う。
        // 無料鑑賞券は来場（スタンプ）より後に発行・使用されなければならないため、
        // 使用する上映回（[0]）は来場に使う上映回（[1]以降）より新しい必要がある
        $pastScreenings = Screening::whereIn('theater_id', $theaterIds)
            ->with('booking')
            ->where('starts_at', '<', now())
            ->orderBy('starts_at', 'desc')
            ->limit(SeedConfig::STAMPS_PER_FREE_TICKET + 1)
            ->get();

        // [0] 未来の通常予約、[1] pending、[2] expired に使う
        $now = now();
        $saleWindowEnd = $now->startOfDay()->addDays(SeedConfig::RESERVATION_SALE_WINDOW_DAYS)->endOfDay();
        $futureScreenings = Screening::whereIn('theater_id', $theaterIds)
            ->with('booking')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $saleWindowEnd)
            ->orderBy('starts_at')
            ->limit(3)
            ->get();

        if ($pastScreenings->count() < SeedConfig::STAMPS_PER_FREE_TICKET + 1 || $futureScreenings->count() < 3) {
            $this->command->warn('祇園ムビの上映回が不足しているため GionReservationSeeder をスキップしました。');

            return;
        }

        DB::transaction(function () use ($pastScreenings, $futureScreenings, $member, $ticketType) {
            // [1..STAMPS_PER_FREE_TICKET]（$pastScreenings の2番目以降）を来場に使う。
            // starts_at 降順のため、この中で最も新しいのは stampScreenings[0]
            $stampScreenings = array_slice($pastScreenings->all(), 1, SeedConfig::STAMPS_PER_FREE_TICKET);

            // 決済済み・過去上映回（4.5.1のスタンプ付与対象）をスタンプ交換に必要な数
            // （4.5.1-2の5個）だけ作り、無料鑑賞券への交換を実際に再現する。
            // うち1件は不来場（no-show）とする（入場有無を問わず付与されることの確認）
            $stamps = [];
            foreach ($stampScreenings as $i => $screening) {
                $stamps[] = $this->seedPastVisit($screening, $member, $ticketType, checkedIn: $i !== 1);
            }
            // スタンプは来場のうち最も新しい上映回（stampScreenings[0]）の開始時点で
            // 5個に到達する（4.5.1-5「上映回の開始時点」）ため、無料鑑賞券の発行日時も揃える
            $freeTicket = $this->exchangeStampsForFreeTicket($member, $stamps, $stampScreenings[0]->starts_at);

            $this->seedCancelledReservation($stampScreenings[0], $member, $ticketType);
            $this->seedGuestCheckedInVisit($stampScreenings[1], $ticketType);

            // 無料鑑賞券使用の予約は、スタンプの来場（$stampScreenings）よりも新しい
            // 過去上映回（$pastScreenings[0]）に作る。発行より後に使用するという
            // 時系列を保つため。未来の上映回ではスタンプ自体がまだ付与されない
            // （4.5.1-5）ため、無料鑑賞券使用席に限ってスタンプが付かないという
            // 4.5.1-4の効果を、他の過去の来場との対比で確認できるようにする
            $this->seedFreeTicketUsage($pastScreenings[0], $member, $ticketType, $freeTicket);

            $this->seedUpcomingReservation($futureScreenings[0], $member, $ticketType);
            $this->seedPendingReservation($futureScreenings[1], $ticketType);
            $this->seedExpiredReservation($futureScreenings[2], $ticketType);
        });
    }

    /**
     * 決済済み・過去上映回の予約を1件作成し、付与されるスタンプを返す（4.5.1）。
     * 入場有無を問わず付与するため、不来場（$checkedIn = false）でもスタンプは付く。
     */
    private function seedPastVisit(Screening $screening, User $member, TicketType $ticketType, bool $checkedIn): Stamp
    {
        $seat = $this->pickSeat($screening);
        $amount = $this->seatAmount($screening, $seat, $ticketType);
        $purchasedAt = $screening->starts_at->subDays(2);
        $checkedInAt = $checkedIn ? $screening->starts_at->addMinutes(5) : null;

        $reservation = (new Reservation)->fill([
            'reservation_no' => $this->nextReservationNo(),
            'user_id' => $member->id,
            'contact_type' => ContactType::Member,
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Paid,
            'total_amount' => $amount,
            'entry_code' => Str::random(32),
        ]);
        $reservation->created_at = $purchasedAt;
        $reservation->updated_at = $checkedInAt ?? $purchasedAt;
        $reservation->checked_in_at = $checkedInAt;
        $reservation->stripe_payment_intent_id = 'pi_seed_gion_'.$reservation->reservation_no;
        $reservation->save();

        $this->createReservationSeat($reservation, $screening, $seat, $ticketType, $amount, $purchasedAt);

        $stamp = new Stamp;
        $stamp->fill(['user_id' => $member->id, 'reservation_id' => $reservation->id]);
        $stamp->created_at = $screening->starts_at;
        $stamp->updated_at = $screening->starts_at;
        $stamp->save();

        return $stamp;
    }

    /**
     * 決済済み・過去上映回・キャンセル済みの予約を1件作成する（4.4）。
     * 座席は解放済み（released_at 設定）とし、再販可能な状態に戻す（6.4.2）。
     */
    private function seedCancelledReservation(Screening $screening, User $member, TicketType $ticketType): void
    {
        $seat = $this->pickSeat($screening);
        $amount = $this->seatAmount($screening, $seat, $ticketType);
        $purchasedAt = $screening->starts_at->subDays(2);
        $cancelledAt = $screening->starts_at->subDay();

        $reservation = (new Reservation)->fill([
            'reservation_no' => $this->nextReservationNo(),
            'user_id' => $member->id,
            'contact_type' => ContactType::Member,
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Cancelled,
            'total_amount' => $amount,
            'entry_code' => Str::random(32),
        ]);
        $reservation->created_at = $purchasedAt;
        $reservation->updated_at = $cancelledAt;
        $reservation->cancelled_at = $cancelledAt;
        $reservation->refunded_at = $cancelledAt;
        $reservation->stripe_payment_intent_id = 'pi_seed_gion_'.$reservation->reservation_no;
        $reservation->save();

        $reservationSeat = $this->createReservationSeat($reservation, $screening, $seat, $ticketType, $amount, $purchasedAt);
        $reservationSeat->released_at = $cancelledAt;
        $reservationSeat->save();
    }

    /**
     * 非会員・決済済み・過去上映回・入場済みの予約を1件作成する（4.3.5の予約照会確認用）。
     */
    private function seedGuestCheckedInVisit(Screening $screening, TicketType $ticketType): void
    {
        $seat = $this->pickSeat($screening);
        $amount = $this->seatAmount($screening, $seat, $ticketType);
        $purchasedAt = $screening->starts_at->subDay();
        $checkedInAt = $screening->starts_at->addMinutes(5);
        $guest = SeedConfig::GUEST_NAMES[0];

        $reservation = (new Reservation)->fill([
            'reservation_no' => $this->nextReservationNo(),
            'guest_name' => $guest['name'],
            'guest_name_kana' => $guest['kana'],
            'contact_type' => ContactType::Guest,
            'guest_email' => 'gion-demo-guest@example.com',
            'guest_phone' => '09011112222',
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Paid,
            'total_amount' => $amount,
            'entry_code' => Str::random(32),
        ]);
        $reservation->created_at = $purchasedAt;
        $reservation->updated_at = $checkedInAt;
        $reservation->checked_in_at = $checkedInAt;
        $reservation->stripe_payment_intent_id = 'pi_seed_gion_'.$reservation->reservation_no;
        $reservation->save();

        $this->createReservationSeat($reservation, $screening, $seat, $ticketType, $amount, $purchasedAt);
    }

    /**
     * 会員・決済済み・未来（販売期間内）・未入場の予約を1件作成する（QRコード表示の確認用）。
     */
    private function seedUpcomingReservation(Screening $screening, User $member, TicketType $ticketType): void
    {
        $seat = $this->pickSeat($screening);
        $amount = $this->seatAmount($screening, $seat, $ticketType);
        $purchasedAt = CarbonImmutable::now();

        $reservation = (new Reservation)->fill([
            'reservation_no' => $this->nextReservationNo(),
            'user_id' => $member->id,
            'contact_type' => ContactType::Member,
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Paid,
            'total_amount' => $amount,
            'entry_code' => Str::random(32),
        ]);
        $reservation->created_at = $purchasedAt;
        $reservation->updated_at = $purchasedAt;
        $reservation->stripe_payment_intent_id = 'pi_seed_gion_'.$reservation->reservation_no;
        $reservation->save();

        $this->createReservationSeat($reservation, $screening, $seat, $ticketType, $amount, $purchasedAt);
    }

    /**
     * 無料鑑賞券を使用した予約を1件作成する（4.5.2）。券種価格のみ0円になり、
     * 上映規格・座席種別の追加料金は別途課金される。支払金額が0円の場合のみ
     * 決済フローをスキップする方針（4.5.2「決済のスキップ条件」）に合わせ、
     * 追加料金が発生する場合のみ `stripe_payment_intent_id` を設定する。
     * 過去の上映回への予約とし、無料鑑賞券を使用した席にはスタンプを付与しない
     * （4.5.1-4）ことを、他の過去の来場（`seedPastVisit`）との対比で確認できるようにする。
     */
    private function seedFreeTicketUsage(Screening $screening, User $member, TicketType $ticketType, FreeTicket $freeTicket): void
    {
        $seat = $this->pickSeat($screening);
        $amount = $this->seatAmount($screening, $seat, $ticketType, freeTicketApplied: true);
        // 発行（issued_at）より後、かつ上映開始以前になるよう、
        // 「上映2日前」と「発行時刻」のいずれか遅い方を購入時刻とする
        $purchasedAt = $freeTicket->issued_at->max($screening->starts_at->subDays(2));

        $reservation = (new Reservation)->fill([
            'reservation_no' => $this->nextReservationNo(),
            'user_id' => $member->id,
            'contact_type' => ContactType::Member,
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Paid,
            'total_amount' => $amount,
            'free_ticket_id' => $freeTicket->id,
            'entry_code' => Str::random(32),
        ]);
        $reservation->created_at = $purchasedAt;
        $reservation->updated_at = $purchasedAt;

        if ($amount > 0) {
            $reservation->stripe_payment_intent_id = 'pi_seed_gion_'.$reservation->reservation_no;
        }

        $reservation->save();

        $this->createReservationSeat($reservation, $screening, $seat, $ticketType, $amount, $purchasedAt);

        $freeTicket->used_at = $purchasedAt;
        $freeTicket->reservation_id = $reservation->id;
        $freeTicket->save();
    }

    /**
     * 非会員・座席選択中（決済未完了）の予約を1件作成する（4.3.3）。
     * `t_reservation_seats` は決済完了時にのみ作成するため（6.4.2）、座席は確保しない
     * （`pickSeat()` は想定金額を算出するためだけに呼ぶ。この座席自体は実際には
     * 予約されない）。作成日時は決済フローの途中で止まっている想定のため
     * 実行時刻のままでよく、`created_at` を明示しない。
     */
    private function seedPendingReservation(Screening $screening, TicketType $ticketType): void
    {
        $seat = $this->pickSeat($screening);
        $amount = $this->seatAmount($screening, $seat, $ticketType);
        $guest = SeedConfig::GUEST_NAMES[1];

        Reservation::create([
            'reservation_no' => $this->nextReservationNo(),
            'guest_name' => $guest['name'],
            'guest_name_kana' => $guest['kana'],
            'contact_type' => ContactType::Guest,
            'guest_email' => 'gion-demo-pending@example.com',
            'guest_phone' => '09033334444',
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Pending,
            'total_amount' => $amount,
            'expires_at' => CarbonImmutable::now()->addMinutes(15),
        ]);
    }

    /**
     * 座席ロックの期限切れにより無効化された予約を1件作成する（4.3.3）。
     * pending と同様、座席は確保しない（6.4.2）。`pickSeat()` は想定金額の
     * 算出のみに使う。
     */
    private function seedExpiredReservation(Screening $screening, TicketType $ticketType): void
    {
        $seat = $this->pickSeat($screening);
        $amount = $this->seatAmount($screening, $seat, $ticketType);
        $now = CarbonImmutable::now();
        $guest = SeedConfig::GUEST_NAMES[2];

        $reservation = (new Reservation)->fill([
            'reservation_no' => $this->nextReservationNo(),
            'guest_name' => $guest['name'],
            'guest_name_kana' => $guest['kana'],
            'contact_type' => ContactType::Guest,
            'guest_email' => 'gion-demo-expired@example.com',
            'guest_phone' => '09055556666',
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Expired,
            'total_amount' => $amount,
            'expires_at' => $now->subMinutes(30),
        ]);
        $reservation->created_at = $now->subMinutes(45);
        $reservation->updated_at = $now->subMinutes(30);
        $reservation->save();
    }

    /**
     * @param  array<int, Stamp>  $stamps
     */
    private function exchangeStampsForFreeTicket(User $member, array $stamps, CarbonImmutable $issuedAt): FreeTicket
    {
        $freeTicket = new FreeTicket;
        $freeTicket->fill([
            'user_id' => $member->id,
            'code' => Str::upper(Str::random(12)),
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->addYear(),
        ]);
        $freeTicket->created_at = $issuedAt;
        $freeTicket->updated_at = $issuedAt;
        $freeTicket->save();

        foreach ($stamps as $stamp) {
            $stamp->free_ticket_id = $freeTicket->id;
            $stamp->save();
        }

        return $freeTicket;
    }

    private function createReservationSeat(
        Reservation $reservation,
        Screening $screening,
        Seat $seat,
        TicketType $ticketType,
        int $amount,
        CarbonImmutable $createdAt,
    ): ReservationSeat {
        $reservationSeat = new ReservationSeat;
        $reservationSeat->fill([
            'reservation_id' => $reservation->id,
            'screening_id' => $screening->id,
            'seat_id' => $seat->id,
            'ticket_type_id' => $ticketType->id,
            'amount' => $amount,
        ]);
        $reservationSeat->created_at = $createdAt;
        $reservationSeat->updated_at = $createdAt;
        $reservationSeat->save();

        return $reservationSeat;
    }

    private function seatAmount(Screening $screening, Seat $seat, TicketType $ticketType, bool $freeTicketApplied = false): int
    {
        $ticketPrice = $freeTicketApplied ? 0 : $ticketType->price;

        return $ticketPrice + $screening->booking->surcharge + $seat->seatType->surcharge;
    }

    private function pickSeat(Screening $screening): Seat
    {
        $usedSeatIds = array_keys($this->usedSeatIdsByScreening[$screening->id] ?? []);

        $seat = Seat::available()
            ->with('seatType')
            ->where('theater_id', $screening->theater_id)
            ->whereNotIn('id', $usedSeatIds === [] ? [0] : $usedSeatIds)
            ->orderBy('id')
            ->firstOrFail();

        $this->usedSeatIdsByScreening[$screening->id][$seat->id] = true;

        return $seat;
    }

    private function nextReservationNo(): string
    {
        do {
            $candidate = str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
        } while (Reservation::where('reservation_no', $candidate)->exists());

        return $candidate;
    }
}
