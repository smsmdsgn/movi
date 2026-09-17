<?php

namespace App\Services;

use App\Models\Screening;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Session;

/**
 * 予約フローが画面をまたいで持ち越す入力（13.4.7）。
 *
 * `t_reservations` は決済完了時にしか作られず（6.4.2）、Livewire のコンポーネント状態は
 * 画面をまたげない。常駐プロセスを持てない制約（環境）もあるため、**セッションに単一の
 * キーで置く**。`SESSION_DRIVER=database` のため実体はデータベースにある。
 *
 * ```
 * session('reservation') = [
 *     'screening_id' => int,        // 対象の上映回
 *     'agreed_at'    => string|null, // P-32 で利用規約に同意した時刻（ISO 8601）
 *     'guest'        => array|null,  // P-34 で入力したお客様情報（非会員）
 *     'tickets'      => array|null,  // P-35 で割り当てた券種（座席ID => 券種ID）
 * ]
 * ```
 *
 * **上映回が変われば丸ごと捨てる。** 別の回を選び直した利用者に、前の回で得た同意や
 * 入力を引き継がせない（12章 残課題25 が求める「上映回が変われば無効とする」）。
 *
 * **座席ロックの期限とは連動させない。** ロックが切れた利用者は P-31 からやり直すが、
 * 同じ回を選び直した場合に同意を再度求める必要はない（同意の対象は上映回であり、
 * 座席ではない。4.3.7）。**券種の割り当ては座席に紐づく**ため、保持していない座席の
 * 分は読み出し側（P-35）が捨てる（4.3.13）。
 *
 * **書き込みは `Screening`、読み出しは上映回IDを受け取る。** 使うのは `->id` だけだが、
 * 書き込み時は実在する上映回であることを型で要求する（A-09 が削除した回に対して同意や
 * 入力を記録させない）。読み出し側は `ResolvesScreening` が保持するIDから呼ぶため、
 * モデルを要求すると削除済みの回で読み出せなくなる（4.3.10）。
 *
 * @phpstan-type GuestInput array{name: string, name_kana: string, phone: string, email: string}
 * @phpstan-type DraftState array{screening_id: int, agreed_at: string|null, guest: GuestInput|null, tickets: array<int, int>|null}
 */
class ReservationDraft
{
    private const string SESSION_KEY = 'reservation';

    /**
     * 利用規約に同意した状態にする（P-32、4.3.7-7）。
     */
    public function agree(Screening $screening): void
    {
        $draft = $this->draftFor($screening->id);
        $draft['agreed_at'] = CarbonImmutable::now()->toIso8601String();

        $this->put($draft);
    }

    /**
     * 対象の上映回について同意を得ているか（12章 残課題25）。
     */
    public function hasAgreed(int $screeningId): bool
    {
        $draft = $this->current();

        return $draft !== null
            && $draft['screening_id'] === $screeningId
            && $draft['agreed_at'] !== null;
    }

    /**
     * お客様情報を記録する（P-34、4.3.6）。
     *
     * @param  GuestInput  $guest
     */
    public function putGuest(Screening $screening, array $guest): void
    {
        $draft = $this->draftFor($screening->id);
        $draft['guest'] = $guest;

        $this->put($draft);
    }

    /**
     * 記録済みのお客様情報。上映回が一致しない場合は null を返す。
     *
     * @return GuestInput|null
     */
    public function guest(int $screeningId): ?array
    {
        $draft = $this->current();

        return $draft !== null && $draft['screening_id'] === $screeningId
            ? $draft['guest']
            : null;
    }

    /**
     * 券種の割り当てを記録する（P-35、7.10）。
     *
     * @param  array<int, int>  $tickets  座席ID => 券種ID
     */
    public function putTickets(Screening $screening, array $tickets): void
    {
        $draft = $this->draftFor($screening->id);
        $draft['tickets'] = $tickets;

        $this->put($draft);
    }

    /**
     * 記録済みの券種の割り当て。上映回が一致しない場合は空配列を返す。
     *
     * **保持していない座席の分も含まれうる。** 座席を選び直した利用者の記録が残るため、
     * 呼び出し側が現在のロックと突き合わせて絞り込む（4.3.13）。
     *
     * @return array<int, int> 座席ID => 券種ID
     */
    public function tickets(int $screeningId): array
    {
        $draft = $this->current();

        return $draft !== null && $draft['screening_id'] === $screeningId
            ? ($draft['tickets'] ?? [])
            : [];
    }

    /**
     * 現在の記録。形式が想定と異なる場合は null を返す。
     *
     * セッションの中身は本クラスだけが書くが、`SESSION_LIFETIME`（既定120分）をまたいだ
     * 再開や、後続の工程での構造変更によって古い形式が残りうる。**キーの欠落は
     * `ErrorException` になって500を返す**ため、読み出し時にすべてのキーを確かめる。
     *
     * @return DraftState|null
     */
    private function current(): ?array
    {
        $draft = Session::get(self::SESSION_KEY);

        if (! is_array($draft) || ! isset($draft['screening_id']) || ! is_int($draft['screening_id'])) {
            return null;
        }

        foreach (['agreed_at', 'guest', 'tickets'] as $key) {
            if (! array_key_exists($key, $draft)) {
                return null;
            }
        }

        if ($draft['agreed_at'] !== null && ! is_string($draft['agreed_at'])) {
            return null;
        }

        if ($draft['guest'] !== null && ! $this->isGuestShape($draft['guest'])) {
            return null;
        }

        if ($draft['tickets'] !== null && ! $this->isTicketsShape($draft['tickets'])) {
            return null;
        }

        /** @var DraftState $draft */
        return $draft;
    }

    /**
     * お客様情報が想定の形（4項目がすべて文字列）か。
     *
     * @phpstan-assert-if-true GuestInput $guest
     */
    private function isGuestShape(mixed $guest): bool
    {
        if (! is_array($guest)) {
            return false;
        }

        foreach (['name', 'name_kana', 'phone', 'email'] as $key) {
            if (! isset($guest[$key]) || ! is_string($guest[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 券種の割り当てが想定の形（座席ID・券種IDともに整数）か。
     *
     * @phpstan-assert-if-true array<int, int> $tickets
     */
    private function isTicketsShape(mixed $tickets): bool
    {
        if (! is_array($tickets)) {
            return false;
        }

        foreach ($tickets as $seatId => $ticketTypeId) {
            if (! is_int($seatId) || ! is_int($ticketTypeId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 対象の上映回に対する記録。別の回のものが残っていれば捨てて作り直す。
     *
     * @return DraftState
     */
    private function draftFor(int $screeningId): array
    {
        $draft = $this->current();

        if ($draft !== null && $draft['screening_id'] === $screeningId) {
            return $draft;
        }

        return ['screening_id' => $screeningId, 'agreed_at' => null, 'guest' => null, 'tickets' => null];
    }

    /**
     * @param  DraftState  $draft
     */
    private function put(array $draft): void
    {
        Session::put(self::SESSION_KEY, $draft);
    }
}
