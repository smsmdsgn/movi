<?php

namespace Database\Seeders;

use App\Enums\ContactType;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\Stamp;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\DeterministicRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 予約（t_reservations / t_reservation_seats / t_stamps）を投入する
 * （docs/design.md 9.1 / 9.2「予約データ」）。
 *
 * 9.1は祇園ムビの予約データを「動作確認用に手動投入」、その他6館を「シーダー」と
 * 区分しているため、本シーダーは祇園ムビを除く6館の上映回のみを対象とする
 * （祇園ムビの手動データは別途用意する。9.3追記表参照）。
 *
 * 9.2は生成期間を上映編成・上映回と「同上」（過去2年〜未来2週間）と定めるが、
 * 全期間・全上映回（約16万回）に予約を生成すると数百万行規模となり現実的でないため、
 * `SeedConfig::RESERVATION_PAST_DAYS` により直近のみへ縮小する。未来側は
 * 4.3.1の販売期間（上映日の3日前0:00から）を超える上映回には予約を生成しない
 * （`SeedConfig::RESERVATION_SALE_WINDOW_DAYS`。9.3追記表参照）。
 *
 * 上映回ごとに座席稼働率を5〜80%の範囲でランダムに設定し（9.2）、
 * 稼働させる座席を1〜8席（4.3.4の上限）単位の予約へ分割する。
 * 金額は6.5.4の基本式（券種価格＋上映編成の追加料金＋座席種別の追加料金）のみを用い、
 * 6.5.2の割引（レイトショー・ペア割）はシーダーでは再現しない（9.3追記表参照）。
 * すべて決済済み（`paid`）とし、過去の上映回の予約のみ `SeedConfig::RESERVATION_CHECKED_IN_PERCENT`
 * の確率で入場済みとする（9.2）。`pending` / `expired` / `cancelled`、無料鑑賞券の使用は
 * シーダーでは生成しない。スタンプは4.5.1-2の5個到達時の無料鑑賞券発行を再現せず、
 * `SeedConfig::RESERVATION_STAMP_CAP`（4個）で頭打ちにする（9.3追記表参照）。
 *
 * 予約日時（`created_at`）は4.3.1の販売期間（上映日の3日前0:00〜上映開始、未来の
 * 上映回では実行時刻まで）の範囲で決定的に分散させる。過去の上映回の入場日時
 * （`checked_in_at`）は必ず上映開始以降のため、予約日時より後になる。
 *
 * 冪等性は上映回単位で判定する（`whereDoesntHave('reservations')`）。他の
 * シーダー（祇園ムビの手動データ等）が対象外の上映回へ予約を作成しても影響しない。
 * 販売期間が実行時刻とともに移動するため、後日の再実行で新たに対象となる
 * 上映回が生じうる。`reservation_no` の重複防止・スタンプ上限のカウントは、
 * このシーダー実行内のメモリだけでなく `run()` 冒頭でDBの既存行から引き継ぐ。
 */
class ReservationSeeder extends Seeder
{
    use DeterministicRandom;

    private const int FLUSH_THRESHOLD = 500;

    /** @var array<int, array<int, int>> theaterId => [seatId => 座席種別の追加料金] */
    private array $seatSurchargeByTheater = [];

    /**
     * @var array<string, bool> 採番済みの予約番号（重複防止）。run() 冒頭でDBの
     *                          既存行から引き継ぐため、増分実行でも既存値と衝突しない
     */
    private array $usedReservationNumbers = [];

    /**
     * @var array<int, int> userId => 未交換のまま付与済みのスタンプ数。run() 冒頭で
     *                      DBの既存 t_stamps（free_ticket_id が null の行）から引き継ぐ
     */
    private array $stampCountByUser = [];

    /** 採番済みの非会員連絡先の数。run() 冒頭でDBの既存 guest 予約件数から引き継ぐ */
    private int $guestSequence = 0;

    /** @var array<int, array<string, mixed>> */
    private array $reservationRows = [];

    /** @var array<string, CarbonImmutable> reservation_no => 予約日時（子行の created_at にも使う） */
    private array $reservationCreatedAtByNo = [];

    /** @var array<string, array<int, array<string, mixed>>> reservation_no => reservation_seat の行（reservation_id を除く） */
    private array $seatRowsByNo = [];

    /** @var array<int, array{reservation_no: string, user_id: int, created_at: CarbonImmutable}> */
    private array $stampEntries = [];

    public function run(): void
    {
        $this->cacheSeatSurcharges();

        $memberIds = User::query()->pluck('id')->all();
        $ticketTypes = TicketType::query()->orderBy('display_order')->get();

        if ($ticketTypes->isEmpty()) {
            return;
        }

        $this->assertTicketTypeWeightsAreValid($ticketTypes);

        // 冪等性を上映回単位（whereDoesntHave）にしたことで、既存データがある状態からの
        // 増分実行が実際に発生しうる。以下の3つはシーダー実行内のカウントだけでは
        // 既存行を見落とすため、事前にDBから読み込んで引き継ぐ（9.3追記表参照）。
        $this->usedReservationNumbers = array_fill_keys(Reservation::query()->pluck('reservation_no')->all(), true);
        $this->guestSequence = Reservation::query()->where('contact_type', ContactType::Guest->value)->count();
        $this->stampCountByUser = DB::table('t_stamps')
            ->whereNull('free_ticket_id')
            ->selectRaw('user_id, count(*) as stamp_count')
            ->groupBy('user_id')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->user_id => (int) $row->stamp_count])
            ->all();

        $now = CarbonImmutable::now();
        $windowStart = $now->subDays(SeedConfig::RESERVATION_PAST_DAYS)->startOfDay();
        // 4.3.1「上映日の3日前0:00から」に合わせ、日付境界で切る
        $saleWindowEnd = $now->startOfDay()->addDays(SeedConfig::RESERVATION_SALE_WINDOW_DAYS)->endOfDay();

        Screening::query()
            ->whereHas('theater.cinema', fn ($query) => $query->where('slug', '!=', SeedConfig::GION_SLUG))
            ->where('starts_at', '>=', $windowStart)
            ->where('starts_at', '<=', $saleWindowEnd)
            ->whereDoesntHave('reservations')
            ->with('booking')
            ->orderBy('id')
            ->chunkById(500, function (Collection $screenings) use ($memberIds, $ticketTypes, $now) {
                foreach ($screenings as $screening) {
                    $this->generateForScreening($screening, $memberIds, $ticketTypes, $now);
                }
            });

        $this->flush();
    }

    /**
     * @param  Collection<int, TicketType>  $ticketTypes
     */
    private function assertTicketTypeWeightsAreValid(Collection $ticketTypes): void
    {
        $names = $ticketTypes->pluck('name')->all();
        $missing = array_diff($names, array_keys(SeedConfig::TICKET_TYPE_WEIGHTS));

        if ($missing !== []) {
            throw new RuntimeException(
                'SeedConfig::TICKET_TYPE_WEIGHTS に券種の重みが定義されていません: '.implode(', ', $missing)
            );
        }

        // DB上に実在する券種のみに絞って合計を求める（PHPStanはリテラル定数どうしの比較を
        // 「常に真/偽」と判定するため、$namesという実行時の値を経由させて回避する）
        $usedWeights = array_intersect_key(SeedConfig::TICKET_TYPE_WEIGHTS, array_flip($names));

        if (array_sum($usedWeights) !== 100) {
            throw new RuntimeException('SeedConfig::TICKET_TYPE_WEIGHTS（DB上に存在する券種分）の合計は100である必要があります。');
        }
    }

    private function cacheSeatSurcharges(): void
    {
        Seat::available()
            ->with('seatType:id,surcharge')
            ->get(['id', 'theater_id', 'seat_type_id'])
            ->each(function (Seat $seat) {
                $this->seatSurchargeByTheater[$seat->theater_id][$seat->id] = $seat->seatType->surcharge;
            });
    }

    /**
     * @param  array<int, int>  $memberIds
     * @param  Collection<int, TicketType>  $ticketTypes
     */
    private function generateForScreening(Screening $screening, array $memberIds, Collection $ticketTypes, CarbonImmutable $now): void
    {
        $seatSurcharges = $this->seatSurchargeByTheater[$screening->theater_id] ?? [];

        if ($seatSurcharges === []) {
            return;
        }

        $shuffledSeatIds = $this->deterministicShuffle(array_keys($seatSurcharges), $screening->id);

        $occupancyPercent = SeedConfig::RESERVATION_MIN_OCCUPANCY_PERCENT + $this->deterministicIndex(
            "occupancy-{$screening->id}",
            SeedConfig::RESERVATION_MAX_OCCUPANCY_PERCENT - SeedConfig::RESERVATION_MIN_OCCUPANCY_PERCENT + 1
        );
        $occupiedCount = (int) round(count($shuffledSeatIds) * $occupancyPercent / 100);
        $occupiedSeatIds = array_slice($shuffledSeatIds, 0, $occupiedCount);

        $isPast = $screening->starts_at->lt($now);
        $bookingSurcharge = $screening->booking->surcharge;

        $offset = 0;
        $groupIndex = 0;

        while ($offset < count($occupiedSeatIds)) {
            $groupSize = SeedConfig::RESERVATION_GROUP_SIZES[
                $this->deterministicIndex("group-{$screening->id}-{$groupIndex}", count(SeedConfig::RESERVATION_GROUP_SIZES))
            ];
            $seatIdsForGroup = array_slice($occupiedSeatIds, $offset, $groupSize);
            $offset += count($seatIdsForGroup);

            $this->buildReservation(
                $screening,
                $seatIdsForGroup,
                $seatSurcharges,
                $memberIds,
                $ticketTypes,
                $isPast,
                $bookingSurcharge,
                $now,
                $groupIndex
            );
            $groupIndex++;

            if (count($this->reservationRows) >= self::FLUSH_THRESHOLD) {
                $this->flush();
            }
        }
    }

    /**
     * @param  array<int, int>  $seatIds
     * @param  array<int, int>  $seatSurcharges  seatId => 座席種別の追加料金
     * @param  array<int, int>  $memberIds
     * @param  Collection<int, TicketType>  $ticketTypes
     */
    private function buildReservation(
        Screening $screening,
        array $seatIds,
        array $seatSurcharges,
        array $memberIds,
        Collection $ticketTypes,
        bool $isPast,
        int $bookingSurcharge,
        CarbonImmutable $now,
        int $groupIndex,
    ): void {
        $seed = "{$screening->id}-{$groupIndex}";
        $reservationNo = $this->nextReservationNo();
        $createdAt = $this->purchasedAt($screening->starts_at, $now, $seed);

        $contactType = $memberIds === [] || $this->deterministicRatio("contact-{$seed}") >= SeedConfig::MEMBER_RESERVATION_PERCENT
            ? ContactType::Guest
            : ContactType::Member;

        $userId = null;
        $guestName = null;
        $guestNameKana = null;
        $guestEmail = null;
        $guestPhone = null;

        if ($contactType === ContactType::Member) {
            $userId = $memberIds[$this->deterministicIndex("member-{$seed}", count($memberIds))];
        } else {
            $this->guestSequence++;
            $guest = SeedConfig::GUEST_NAMES[$this->guestSequence % count(SeedConfig::GUEST_NAMES)];
            $guestName = $guest['name'];
            $guestNameKana = $guest['kana'];
            $guestEmail = "guest{$this->guestSequence}@example.com";
            $guestPhone = '090'.str_pad((string) ($this->guestSequence % 100_000_000), 8, '0', STR_PAD_LEFT);
        }

        $totalAmount = 0;
        $seatRows = [];

        foreach ($seatIds as $i => $seatId) {
            $ticketType = $this->pickTicketType($ticketTypes, "ticket-{$seed}-{$i}");
            $amount = $ticketType->price + $bookingSurcharge + $seatSurcharges[$seatId];
            $totalAmount += $amount;

            $seatRows[] = [
                'screening_id' => $screening->id,
                'seat_id' => $seatId,
                'ticket_type_id' => $ticketType->id,
                'amount' => $amount,
            ];
        }

        $checkedInAt = null;

        if ($isPast && $this->deterministicRatio("checkin-{$seed}") < SeedConfig::RESERVATION_CHECKED_IN_PERCENT) {
            $minutesAfterStart = 1 + $this->deterministicIndex("checkin-minutes-{$seed}", SeedConfig::RESERVATION_CHECKIN_WINDOW_MINUTES);
            $checkedInAt = $screening->starts_at->addMinutes($minutesAfterStart)->min($screening->ends_at);
        }

        $this->reservationRows[] = [
            'reservation_no' => $reservationNo,
            'user_id' => $userId,
            'guest_name' => $guestName,
            'guest_name_kana' => $guestNameKana,
            'contact_type' => $contactType->value,
            'guest_email' => $guestEmail,
            'guest_phone' => $guestPhone,
            'screening_id' => $screening->id,
            'status' => ReservationStatus::Paid->value,
            'total_amount' => $totalAmount,
            'entry_code' => Str::random(32),
            'stripe_payment_intent_id' => "pi_seed_{$reservationNo}",
            'checked_in_at' => $checkedInAt,
            'created_at' => $createdAt,
            'updated_at' => $checkedInAt ?? $createdAt,
        ];

        $this->reservationCreatedAtByNo[$reservationNo] = $createdAt;
        $this->seatRowsByNo[$reservationNo] = $seatRows;

        if ($isPast && $contactType === ContactType::Member && ($this->stampCountByUser[$userId] ?? 0) < SeedConfig::RESERVATION_STAMP_CAP) {
            $this->stampCountByUser[$userId] = ($this->stampCountByUser[$userId] ?? 0) + 1;
            $this->stampEntries[] = ['reservation_no' => $reservationNo, 'user_id' => $userId, 'created_at' => $screening->starts_at];
        }
    }

    /**
     * 予約日時を、販売開始（上映開始の `SeedConfig::RESERVATION_SALE_WINDOW_DAYS` 日前 0:00）から
     * 実際に購入しえた最終時刻（上映開始と実行時刻のいずれか早い方。未来の上映回は
     * 実行時刻を超えて予約できないため）までの範囲で決定的に求める（4.3.1）。
     */
    private function purchasedAt(CarbonImmutable $screeningStartsAt, CarbonImmutable $now, string $seed): CarbonImmutable
    {
        $saleStart = $screeningStartsAt->startOfDay()->subDays(SeedConfig::RESERVATION_SALE_WINDOW_DAYS);
        $latestPurchase = $screeningStartsAt->min($now);
        $rangeSeconds = max(0, $latestPurchase->getTimestamp() - $saleStart->getTimestamp());
        $offsetSeconds = $rangeSeconds > 0 ? $this->deterministicIndex("purchased-{$seed}", $rangeSeconds) : 0;

        return $saleStart->addSeconds($offsetSeconds);
    }

    /**
     * @param  Collection<int, TicketType>  $ticketTypes
     */
    private function pickTicketType(Collection $ticketTypes, string $seed): TicketType
    {
        $roll = $this->deterministicRatio($seed);
        $cumulative = 0;

        foreach ($ticketTypes as $ticketType) {
            $cumulative += SeedConfig::TICKET_TYPE_WEIGHTS[$ticketType->name] ?? 0;

            if ($roll < $cumulative) {
                return $ticketType;
            }
        }

        return $ticketTypes->last();
    }

    private function nextReservationNo(): string
    {
        do {
            $candidate = str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
        } while (isset($this->usedReservationNumbers[$candidate]));

        $this->usedReservationNumbers[$candidate] = true;

        return $candidate;
    }

    /**
     * @param  array<int, int>  $seatIds
     * @return array<int, int>
     */
    private function deterministicShuffle(array $seatIds, int $screeningId): array
    {
        usort(
            $seatIds,
            fn (int $a, int $b) => (crc32("{$screeningId}-{$a}") & 0x7FFFFFFF) <=> (crc32("{$screeningId}-{$b}") & 0x7FFFFFFF)
        );

        return $seatIds;
    }

    private function flush(): void
    {
        if ($this->reservationRows === []) {
            return;
        }

        DB::transaction(function () {
            Reservation::query()->insert($this->reservationRows);

            /** @var Collection<string, int> $ids reservation_no => id */
            $ids = Reservation::query()
                ->whereIn('reservation_no', array_column($this->reservationRows, 'reservation_no'))
                ->pluck('id', 'reservation_no');

            $seatRows = [];

            foreach ($this->seatRowsByNo as $reservationNo => $rows) {
                $reservationId = $ids[$reservationNo];
                $createdAt = $this->reservationCreatedAtByNo[$reservationNo];

                foreach ($rows as $row) {
                    $row['reservation_id'] = $reservationId;
                    $row['created_at'] = $createdAt;
                    $row['updated_at'] = $createdAt;
                    $seatRows[] = $row;
                }
            }

            ReservationSeat::query()->insert($seatRows);

            if ($this->stampEntries !== []) {
                $stampRows = array_map(
                    fn (array $entry) => [
                        'user_id' => $entry['user_id'],
                        'reservation_id' => $ids[$entry['reservation_no']],
                        'free_ticket_id' => null,
                        'created_at' => $entry['created_at'],
                        'updated_at' => $entry['created_at'],
                    ],
                    $this->stampEntries
                );

                Stamp::query()->insert($stampRows);
            }
        });

        $this->reservationRows = [];
        $this->reservationCreatedAtByNo = [];
        $this->seatRowsByNo = [];
        $this->stampEntries = [];
    }
}
