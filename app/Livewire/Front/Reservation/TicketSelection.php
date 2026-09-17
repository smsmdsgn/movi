<?php

namespace App\Livewire\Front\Reservation;

use App\Livewire\Front\Reservation\Concerns\GuardsReservationStep;
use App\Livewire\Front\Reservation\Concerns\ResolvesScreening;
use App\Livewire\Front\Reservation\Concerns\UsesReservationDraft;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\TicketType;
use App\Services\PriceBreakdown;
use App\Services\PricingService;
use App\Services\SeatLockService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 券種選択（P-35、7.10）。1画面1コンポーネント（13.4.3）。
 *
 * **会員・非会員の双方が合流する画面**（7.18）。会員は P-33 から、非会員は P-34 から届く。
 *
 * 金額は保持せず、描画のたびに `PricingService` へ計算させる（13.4.5）。`PriceBreakdown`
 * は `final readonly class` で公開プロパティに載せられず、載せられたとしてもクライアントから
 * 改変された金額を受け取る経路になる（17章）。同一リクエスト内の重複クエリは
 * private プロパティのメモ化で避ける（Livewire は private を直列化しない。
 * `ResolvesScreening` と同じ手法）。
 *
 * **座席は `t_seat_locks` を読み直して求める**（6.4.3-2。P-31・P-32 と同じ）。券種の
 * 割り当ては座席に紐づくため、保持していない座席の分は捨てる（4.3.13）。
 *
 * 無料鑑賞券の選択（7.10-4）は未実装（12章 残課題31）。
 */
class TicketSelection extends Component
{
    use GuardsReservationStep;
    use ResolvesScreening;
    use UsesReservationDraft;

    /**
     * 座席ごとの券種（座席ID => 券種ID）。未選択は空文字。
     *
     * **`Locked` にしない。** 利用者が選ぶ入力そのものであり、値は保持中の座席・
     * 実在する券種と突き合わせて検証する（17章「クライアントから送られた値を信用しない」）。
     *
     * 型は `array<int, string>` と書かない。**キーも値もクライアントが決める**ため、
     * 座席ID以外のキーや文字列以外の値が届きうる（`resolvedSelections()` が両方を確かめる）。
     *
     * @var array<array-key, mixed>
     */
    public array $selections = [];

    /** 表示中の案内の文言キー（7.17）。言語ファイルのキーを差し替えられないよう `Locked`。 */
    #[Locked]
    public ?string $messageKey = null;

    /**
     * 同一リクエスト内での読み直しを避けるための保持（Livewire は private を直列化しない）。
     *
     * @var EloquentCollection<int, Seat>|null
     */
    private ?EloquentCollection $resolvedSeats = null;

    /** @var EloquentCollection<int, TicketType>|null */
    private ?EloquentCollection $resolvedTicketTypes = null;

    public function mount(Screening $screening): void
    {
        $this->rememberScreening($screening);
        $this->fillFromDraft();
    }

    /**
     * 次へ進む（決済 P-36、7.11）。
     *
     * 判定順は 前提 → 入力 とする。座席を失った利用者に券種の未選択を指摘しても、
     * 次の行動（座席の選び直し）につながらない（P-32・P-34 と同じトーン）。
     */
    public function submit(SeatLockService $locks): void
    {
        $this->messageKey = null;

        if (! $this->canProceed($locks)) {
            return;
        }

        $screening = $this->screeningOnSale();

        // `canProceed()` が真なら販売期間内の上映回が存在する。PHPStan へ示すための分岐。
        if ($screening === null) {
            return;
        }

        $tickets = $this->resolvedSelections();

        // 7.10「券種を選択していない座席がある場合、次へ進めない」。存在しない券種IDも
        // ここで弾かれる（`resolvedSelections()` が券種マスタと突き合わせる）。
        if ($tickets === null) {
            $this->messageKey = 'front.reservation.errors.ticket_type_required';

            return;
        }

        // 保持座席以外のキーを落とす。クライアントは任意の座席IDを積めるため、
        // 残したままだと以後のスナップショットが際限なく膨らむ（保存内容には影響しない）。
        $this->selections = array_intersect_key($this->selections, $tickets);

        $this->draft()->putTickets($screening, $tickets);

        $this->redirect(route('front.reservation.payment', ['id' => $this->screeningId]), navigate: false);
    }

    public function render(SeatLockService $locks): View
    {
        $status = $this->stepStatus($locks);

        return view('front.reservation.ticket-selection', [
            // `$status + [...]` としない。`+` は左辺のキーを優先するため、
            // `noticeKey` の差し替えが無視される。
            'recoveryUrl' => $status['recoveryUrl'],
            'recoveryLabelKey' => $status['recoveryLabelKey'],
            'onSale' => $this->screeningOnSale() !== null,
            'canProceed' => $status['noticeKey'] === null,
            // 前提が崩れている場合はそちらを優先する（座席を失った利用者に
            // 「券種をお選びください」を出さない。P-32 の `noticeKey()` と同じ整理）。
            //
            // **未選択が解消されたら案内も消す。** セレクトは `wire:model.live` のため
            // 送信の合間に再描画が走る。`messageKey` を持ち越すと、金額が表示されている
            // 隣に「券種をお選びください」が残る（4.3.10「描画時点の状態を優先する」）。
            'noticeKey' => $status['noticeKey'] ?? ($this->resolvedSelections() === null ? $this->messageKey : null),
            'seats' => $this->heldSeats(),
            'ticketTypes' => $this->ticketTypes(),
            'breakdown' => $this->breakdown(),
            // 戻り先は P-31（座席選択）に寄せる（4.3.11 / 4.3.13）。**P-33 を指さない。**
            // 会員は P-33 の `mount()` で P-35 へ前送りされるため、押しても同じ画面に
            // 跳ね返る（画面上の唯一の「戻る」が機能しない）。
            'seatsUrl' => route('front.reservation.seats', ['id' => $this->screeningId]),
        ]);
    }

    /**
     * 券種マスタ（6.5.1）。A-07 と同じ並び順で出す。
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

    /**
     * 保持中の座席（6.4.3-2）。座席表（P-31）と同じ並び順で表示する。
     *
     * `SeatLockService` は状態を持たないため、引数で受け取らずここで解決する
     *（`UsesReservationDraft` と同じ扱い）。1リクエストのうちに描画・検証・金額計算から
     * 呼ばれるため、結果を保持して読み直さない。
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
     * 現在の選択に対する金額（7.10-3 / 7.10-5）。
     *
     * **未選択の座席がある間は求めない。** 一部の席だけで計算した小計を出すと、
     * 割引の成否（ペア割は大人の枚数で決まる）が選択の途中で変わって見える。
     */
    private function breakdown(): ?PriceBreakdown
    {
        $screening = $this->screeningOnSale();
        $selections = $this->resolvedSelections();

        return $screening === null || $selections === null
            ? null
            : app(PricingService::class)->calculate($screening, $selections);
    }

    /**
     * 保持中の全席に有効な券種が選ばれている場合のみ、その割り当てを返す。
     *
     * @return array<int, int>|null 座席ID => 券種ID
     */
    private function resolvedSelections(): ?array
    {
        $seats = $this->heldSeats();

        if ($seats->isEmpty()) {
            return null;
        }

        $validTicketTypeIds = $this->ticketTypes()->modelKeys();

        $selections = [];

        foreach ($seats as $seat) {
            $raw = $this->selections[$seat->id] ?? '';

            // 先に型を確かめる。`filter_var()` は `true` を 1 に変換するため、JSON の
            // boolean を送られると「券種ID 1 を選んだ」ことにできてしまう。
            if (! is_string($raw) && ! is_int($raw)) {
                return null;
            }

            // セレクトボックスの値は文字列で届く。空文字（未選択）と非数値はいずれも false。
            $selected = filter_var($raw, FILTER_VALIDATE_INT);

            if ($selected === false || ! in_array($selected, $validTicketTypeIds, true)) {
                return null;
            }

            $selections[$seat->id] = $selected;
        }

        return $selections;
    }

    /**
     * 記録済みの割り当てを書き戻す（P-36 から戻った場合など）。
     *
     * **保持していない座席の分は捨てる。** 座席を選び直した利用者の古い割り当てが
     * 残っているため、現在のロックと突き合わせる（4.3.13）。
     */
    private function fillFromDraft(): void
    {
        $stored = $this->draft()->tickets($this->screeningId);

        if ($stored === []) {
            return;
        }

        foreach ($this->heldSeats() as $seat) {
            if (isset($stored[$seat->id])) {
                $this->selections[$seat->id] = (string) $stored[$seat->id];
            }
        }
    }
}
