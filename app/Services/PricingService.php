<?php

namespace App\Services;

use App\Enums\Discount;
use App\Models\FreeTicket;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\TicketType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * 金額の算出（13.4.5 / 6.5）。**コンポーネント・Blade・コントローラに計算を書かない。**
 *
 * 価格はすべてデータベースから引き直す。クライアントから送られた金額は信用しない（17章）。
 * 受け取るのは「どの座席にどの券種を割り当てたか」だけで、金額は引数に取らない。
 *
 * **割引は席ごとに確定させてから合計する**（6.5.4）。予約単位の割引額を席へ按分すると、
 * 端数の配分規則を別途定める必要が生じ、席ごとの金額を保持する 6.5.5 と噛み合わない。
 *
 * 座席が上映回のシアターに属するかは検証しない。予約確定時の責務として
 * `ReservationService` が行う（13.4.7）。
 */
class PricingService
{
    /** レイトショーの判定に使う開始時刻（6.5.2）。この時刻以降に始まる回が対象。 */
    public const int LATE_SHOW_FROM_HOUR = 20;

    /** レイトショーの1席あたりの割引額（6.5.2）。 */
    public const int LATE_SHOW_DISCOUNT = 500;

    /** ペア割を構成する枚数（6.5.2「大人2枚で3,000円」）。 */
    public const int PAIR_SIZE = 2;

    /** ペア割適用時の1席あたりの券種価格（3,000円 ÷ 2枚。6.5.2-4）。 */
    public const int PAIR_UNIT_PRICE = 1500;

    /**
     * 予約1件分の金額を求める（6.5.4）。
     *
     * @param  array<int, int>  $seatSelections  座席ID => 券種ID
     * @param  FreeTicket|null  $freeTicket  使用する無料鑑賞券（4.5.2）。
     *                                       **保有者の検証は呼び出し側の責務**（本サービスは購入者を知らない）。
     *                                       期限切れ・使用済みの券は適用せず、通常の割引判定へ落とす
     *
     * @throws InvalidArgumentException 存在しない座席IDまたは券種IDが含まれる場合
     */
    public function calculate(Screening $screening, array $seatSelections, ?FreeTicket $freeTicket = null): PriceBreakdown
    {
        if ($seatSelections === []) {
            return new PriceBreakdown([]);
        }

        // 上映編成の追加料金（6.5.3）を読むため、関連を確実に載せる。
        // `preventLazyLoading`（本番以外で有効）は**複数行を水和したコレクション由来の
        // モデルでのみ**違反を投げる（`Builder::hydrate()` の `count($items) > 1`）。
        // 単一行の `find()` 由来では素通りするため、上映回の一覧から渡された場合にだけ
        // 例外になる。呼び出し側の取得方法に依存しないよう、ここで冪等に読み込む。
        $screening->loadMissing('booking');

        // 座席IDの昇順に整える。ペア割で「余りの1枚」が出る場合に、どの席へ割引が付くかを
        // 決定的にするため（大人券種の価格は同一のため合計は変わらないが、
        // `t_reservation_seats.amount` は席ごとに保存される。6.5.5）。
        ksort($seatSelections);

        $seats = $this->loadSeats(array_keys($seatSelections));
        $ticketTypes = $this->loadTicketTypes(array_values($seatSelections));

        $regularAmounts = [];

        foreach ($seatSelections as $seatId => $ticketTypeId) {
            $regularAmounts[$seatId] = $ticketTypes[$ticketTypeId]->price
                + $screening->booking->surcharge
                + $seats[$seatId]->seatType->surcharge;
        }

        if ($freeTicket !== null && $this->isUsable($freeTicket)) {
            return $this->applyFreeTicket($screening, $seatSelections, $regularAmounts, $ticketTypes, $freeTicket);
        }

        return $this->applyBestDiscount($screening, $seatSelections, $regularAmounts, $ticketTypes);
    }

    /**
     * 成立する割引のうち、予約全体の割引額が大きい方を適用する（6.5.2-1）。
     *
     * 双方の割引額が同じ場合はレイトショーを採る。支払金額は変わらないため、
     * 席ごとの配分と 7.10-3 の表示を決定的にするための規約。
     *
     * @param  array<int, int>  $seatSelections  座席ID => 券種ID
     * @param  array<int, int>  $regularAmounts  座席ID => 割引前の金額
     * @param  array<int, TicketType>  $ticketTypes
     */
    private function applyBestDiscount(Screening $screening, array $seatSelections, array $regularAmounts, array $ticketTypes): PriceBreakdown
    {
        /** @var array<string, list<SeatPrice>> $candidates */
        $candidates = [];

        if ($this->isLateShow($screening)) {
            $candidates[Discount::LateShow->value] = $this->lateShowPrices($seatSelections, $regularAmounts);
        }

        $pair = $this->pairPrices($seatSelections, $regularAmounts, $ticketTypes);

        if ($pair !== null) {
            $candidates[Discount::Pair->value] = $pair;
        }

        $best = null;
        $bestAmount = 0;

        foreach ($candidates as $value => $prices) {
            $amount = array_sum(array_map(fn (SeatPrice $seat) => $seat->discountAmount, $prices));

            // 割引額が0の候補は採らない。6.5.2-5 で不適用になった場合（券種価格が
            // 1,500円以下のペア割）と、値引きが0円になる場合を「割引なし」に揃える。
            if ($amount > $bestAmount) {
                $best = Discount::from($value);
                $bestAmount = $amount;
            }
        }

        if ($best === null) {
            return new PriceBreakdown($this->withoutDiscount($seatSelections, $regularAmounts));
        }

        return new PriceBreakdown($candidates[$best->value], $best);
    }

    /**
     * 割引を適用しない金額（6.5.4 の「1席あたり（通常）」のみ）。
     *
     * @param  array<int, int>  $seatSelections  座席ID => 券種ID
     * @param  array<int, int>  $regularAmounts  座席ID => 割引前の金額
     * @return list<SeatPrice>
     */
    private function withoutDiscount(array $seatSelections, array $regularAmounts): array
    {
        $prices = [];

        foreach ($seatSelections as $seatId => $ticketTypeId) {
            $prices[] = new SeatPrice($seatId, $ticketTypeId, $regularAmounts[$seatId], 0);
        }

        return $prices;
    }

    /**
     * レイトショー（6.5.2）。対象の各席から定額を引く。
     *
     * 割引額はその席の金額を超えない（6.5.2-6）。券種価格が500円未満でも0円止まりとし、
     * `unsignedInteger` の列に負の値を作らない。
     *
     * @param  array<int, int>  $seatSelections  座席ID => 券種ID
     * @param  array<int, int>  $regularAmounts  座席ID => 割引前の金額
     * @return list<SeatPrice>
     */
    private function lateShowPrices(array $seatSelections, array $regularAmounts): array
    {
        $prices = [];

        foreach ($seatSelections as $seatId => $ticketTypeId) {
            $regular = $regularAmounts[$seatId];
            $prices[] = new SeatPrice($seatId, $ticketTypeId, $regular, min(self::LATE_SHOW_DISCOUNT, $regular));
        }

        return $prices;
    }

    /**
     * ペア割（6.5.2）。大人券種を2枚単位で、券種価格を定額に置き換える。
     *
     * **券種価格が定額以下のときは適用しない**（6.5.2-5）。A-07 で価格を引き下げると
     * 置き換えが値上げになるため。追加料金は 6.5.3 のとおり別途加算したままとする。
     *
     * @param  array<int, int>  $seatSelections  座席ID => 券種ID
     * @param  array<int, int>  $regularAmounts  座席ID => 割引前の金額
     * @param  array<int, TicketType>  $ticketTypes
     * @return list<SeatPrice>|null 大人券種が2枚に満たない場合は null
     */
    private function pairPrices(array $seatSelections, array $regularAmounts, array $ticketTypes): ?array
    {
        $adultSeatIds = [];

        foreach ($seatSelections as $seatId => $ticketTypeId) {
            if ($ticketTypes[$ticketTypeId]->name === TicketType::ADULT_NAME) {
                $adultSeatIds[] = $seatId;
            }
        }

        $discountedCount = intdiv(count($adultSeatIds), self::PAIR_SIZE) * self::PAIR_SIZE;

        if ($discountedCount === 0) {
            return null;
        }

        // 「余りの1枚は通常料金」（6.5.2）。座席IDの昇順の先頭から2枚単位で充てる。
        $targets = array_flip(array_slice($adultSeatIds, 0, $discountedCount));

        $prices = [];

        foreach ($seatSelections as $seatId => $ticketTypeId) {
            $regular = $regularAmounts[$seatId];
            $discount = isset($targets[$seatId])
                ? max(0, $ticketTypes[$ticketTypeId]->price - self::PAIR_UNIT_PRICE)
                : 0;

            $prices[] = new SeatPrice($seatId, $ticketTypeId, $regular, min($discount, $regular));
        }

        return $prices;
    }

    /**
     * 無料鑑賞券（4.5.2）。**1席分の券種価格のみ**を無料にし、追加料金は残す（4.5.2-2）。
     *
     * 券種価格が最も高い席へ充てる。どの席へ充てるかは 4.5.2 が定めておらず、
     * 利用者にとって有利な配分を既定とする（「保有券の一覧から選択する」のは券であり席ではない）。
     *
     * 割引との併用は不可（4.5.2-6 / 6.5.2-2）のため、レイトショー・ペア割は判定しない。
     *
     * @param  array<int, int>  $seatSelections  座席ID => 券種ID
     * @param  array<int, int>  $regularAmounts  座席ID => 割引前の金額
     * @param  array<int, TicketType>  $ticketTypes
     */
    private function applyFreeTicket(Screening $screening, array $seatSelections, array $regularAmounts, array $ticketTypes, FreeTicket $freeTicket): PriceBreakdown
    {
        $coveredSeatId = null;
        $coveredPrice = 0;

        foreach ($seatSelections as $seatId => $ticketTypeId) {
            if ($ticketTypes[$ticketTypeId]->price > $coveredPrice) {
                $coveredSeatId = $seatId;
                $coveredPrice = $ticketTypes[$ticketTypeId]->price;
            }
        }

        // 券種価格が全席0円なら無料にできる分が無い。券を消費せず通常の割引判定へ落とす
        // （A-07 の下限は1円だがシーダー・直接INSERTでは0円を作れる）。
        if ($coveredSeatId === null) {
            return $this->applyBestDiscount($screening, $seatSelections, $regularAmounts, $ticketTypes);
        }

        $prices = [];

        foreach ($seatSelections as $seatId => $ticketTypeId) {
            $regular = $regularAmounts[$seatId];
            $discount = $seatId === $coveredSeatId ? $ticketTypes[$ticketTypeId]->price : 0;

            $prices[] = new SeatPrice($seatId, $ticketTypeId, $regular, min($discount, $regular));
        }

        return new PriceBreakdown($prices, null, $freeTicket->id);
    }

    /** レイトショーの対象か（6.5.2）。判定は上映回の開始時刻による。 */
    private function isLateShow(Screening $screening): bool
    {
        return $screening->starts_at->hour >= self::LATE_SHOW_FROM_HOUR;
    }

    /** 使用できる無料鑑賞券か（4.5.2-3 / 4.5.2-4）。保有者の検証は呼び出し側が行う。 */
    private function isUsable(FreeTicket $freeTicket): bool
    {
        return $freeTicket->used_at === null
            && $freeTicket->expires_at->isAfter(CarbonImmutable::now());
    }

    /**
     * @param  list<int>  $seatIds
     * @return array<int, Seat>
     *
     * @throws InvalidArgumentException
     */
    private function loadSeats(array $seatIds): array
    {
        /** @var array<int, Seat> $seats */
        $seats = Seat::query()->with('seatType')->findMany($seatIds)->keyBy('id')->all();

        if (count($seats) !== count($seatIds)) {
            throw new InvalidArgumentException('存在しない座席が含まれています。');
        }

        return $seats;
    }

    /**
     * @param  list<int>  $ticketTypeIds
     * @return array<int, TicketType>
     *
     * @throws InvalidArgumentException
     */
    private function loadTicketTypes(array $ticketTypeIds): array
    {
        $unique = array_values(array_unique($ticketTypeIds));

        /** @var array<int, TicketType> $ticketTypes */
        $ticketTypes = TicketType::query()->findMany($unique)->keyBy('id')->all();

        if (count($ticketTypes) !== count($unique)) {
            throw new InvalidArgumentException('存在しない券種が含まれています。');
        }

        return $ticketTypes;
    }
}
