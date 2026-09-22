<?php

namespace App\Livewire\Front\Lookup;

use App\Enums\ContactType;
use App\Livewire\Front\Concerns\CancelsReservation;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 予約照会（P-07、4.3.5 / 7.19）。会員・非会員を問わず利用できる。1画面1コンポーネント
 * （13.4.3）。照合という操作を伴うため Livewire とする（front-ui）。
 *
 * **館非依存ページ（P-05〜P-20）であり `{slug}` を持たない。** ヘッダーの館は直前に
 * 選択したものが出る（4.1.3追記表）。予約が定まった後も現在の館を差し替えない。照会は
 * 館をまたいで行うものであり、1件を開いただけで利用者の館の選択を書き換えるのは行き過ぎる
 * （予約フロー P-31〜P-38 が `CurrentCinemaService::remember()` を呼ぶのは、その一連が
 * 特定の館の購入手続きだからである。4.3.9）。
 *
 * **照合の成否を持ち回る器は Livewire のコンポーネント状態とする。** `#[Locked]` の
 * プロパティはチェックサムで保護され、クライアントから差し替えられない（`ResolvesScreening`
 * が `screeningId` を置くのと同じ扱い）。照合のたびにセッションへ書くと、複数タブで
 * 別の予約を開いた場合に互いを上書きする。
 */
class Index extends Component
{
    /**
     * キャンセルの導線（4.4 / 7.19-8）。**マイページの予約詳細（P-06）と共有する。**
     * 到達の根拠は `cancellableReservation()` / `cancellableReservationId()` が定める。
     */
    use CancelsReservation;

    /** 照会方式A（4.3.5）。予約番号とメールアドレスで照会する。 */
    public const string METHOD_NUMBER = 'number';

    /** 照会方式B（4.3.5）。予約番号を失った場合に、メール・電話・上映日で復旧する。 */
    public const string METHOD_CONTACT = 'contact';

    /** 1分あたりの照会回数の上限（17.2.2）。 */
    private const int PER_MINUTE = 5;

    /** 1時間あたりの照会回数の上限（17.2.2）。 */
    private const int PER_HOUR = 20;

    /** 選択中の照会方式。`METHOD_NUMBER` または `METHOD_CONTACT`。 */
    public string $method = self::METHOD_NUMBER;

    /** 予約番号（8桁。ハイフンの有無を問わない。4.3.5）。 */
    public string $reservationNo = '';

    /** メールアドレス。方式A・B の双方で使う。 */
    public string $email = '';

    /** 電話番号（方式Bのみ。ハイフンなしの半角数字。4.3.6）。 */
    public string $phone = '';

    /** 上映日（方式Bのみ。`Y-m-d`）。 */
    public string $screeningDate = '';

    /**
     * 照合に成功した予約のID（4.3.5「複数件が該当する場合は一覧表示し、選択させる」）。
     *
     * **ここに入っていることが、その予約を参照してよい根拠である。** 会員のログイン状態も
     * `ReservationPolicy` も通らない経路のため（非会員が主な利用者）、照合の結果そのものを
     * 権限として扱う。クライアントから差し替えられないよう `#[Locked]` とする。
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $matchedIds = [];

    /** 一覧から選択された予約のID。`matchedIds` に含まれるものに限る。 */
    #[Locked]
    public ?int $selectedId = null;

    /** 照会を1度でも実行したか。未実行と「該当なし」を画面で区別するために持つ。 */
    #[Locked]
    public bool $searched = false;

    /**
     * 予約確定メールのリンクから開いた場合に予約番号を埋める（4.3.5）。
     *
     * **メールアドレスは埋めない。** URLに載せると、メールの転送やブラウザの履歴から
     * 照合の2要素が揃ってしまう。利用者に1項目を入力させることが照合の意味を保つ。
     */
    public function mount(): void
    {
        $no = request()->query('no');

        if (is_string($no)) {
            $this->reservationNo = mb_substr($no, 0, 20);
        }
    }

    /**
     * 照会を実行する（4.3.5）。
     *
     * 手順は 上限の確認 → 回数の加算 → 入力の検証 → 照合 とする。**加算を検証より先に
     * 置く。** 後に置くと、検証が例外を投げる経路（形式の誤った入力）が1回も数えられず、
     * 「形式が正しいか」を無制限に試せてしまう。総当たりの前段として使えるため、
     * 受け付けた送信はすべて数える。
     */
    public function search(): void
    {
        $this->ensureIsNotRateLimited();

        RateLimiter::hit($this->minuteKey());
        RateLimiter::hit($this->hourKey(), 3600);

        $this->normalizeInput();

        $validated = $this->validate();

        $this->searched = true;
        $this->selectedId = null;

        $matched = $this->matchingReservations($validated);

        $this->matchedIds = $matched->all();

        // 1件だけなら選択の操作を挟まない（一覧に1行だけ並べても選ぶ以外にできない）。
        if (count($this->matchedIds) === 1) {
            $this->selectedId = $this->matchedIds[0];
        }
    }

    /**
     * 一覧から1件を選ぶ（4.3.5「複数件が該当する場合」）。
     *
     * **`matchedIds` に含まれないIDは受け付けない。** 直前の照合で得た範囲に限ることで、
     * 照合を経ずに任意の予約IDを開く経路を塞ぐ。`#[Locked]` により `matchedIds` 自体は
     * 差し替えられないため、ここでの確認と併せて到達の可否が定まる。
     */
    public function select(mixed $id): void
    {
        // クライアントから届く識別子は型宣言に任せず検証する（P-31 の `toggle()` と同じ
        // 扱い。本エンドポイントは未認証の顧客に開かれている。4.3.10）。
        $reservationId = filter_var($id, FILTER_VALIDATE_INT);

        if ($reservationId === false || ! in_array($reservationId, $this->matchedIds, strict: true)) {
            return;
        }

        $this->selectedId = $reservationId;
    }

    /** 一覧へ戻る（複数件が該当した場合のみ画面に出る）。 */
    public function backToList(): void
    {
        if (count($this->matchedIds) > 1) {
            $this->selectedId = null;
        }
    }

    /**
     * 入力し直す。照合の結果を破棄し、フォームだけの状態へ戻す。
     *
     * 入力値そのものは消さない。打ち直しの手間を増やさないためであり、
     * 照合の根拠（`matchedIds`）を落とせば参照はできなくなる。
     */
    public function startOver(): void
    {
        // 方式の切り替え（`updatedMethod()`）と揃える。前の照会の誤りを残したまま
        // フォームへ戻すと、まだ直っていないかのように見える。
        $this->resetErrorBag();
        $this->clearCancelResult();

        $this->matchedIds = [];
        $this->selectedId = null;
        $this->searched = false;
        $this->confirmingCancelId = null;
    }

    public function render(): View
    {
        $selected = $this->selectedReservation();

        return view('front.lookup.lookup-form', [
            'selected' => $selected,
            // 座席とその並び順（7.19-4）は明細の部品が予約から引くため渡さない。
            'candidates' => $this->selectedId === null ? $this->candidates() : collect(),
            // 列挙型・クラス定数はビューで参照せず値で渡す（Blade の `@php` は `use` を
            // 書けず、完全修飾名を並べるとマークアップが読めなくなる）。
            'methodNumber' => self::METHOD_NUMBER,
            'methodContact' => self::METHOD_CONTACT,
            'fields' => $this->formFields(),
            'weekdays' => __('front.schedule.weekdays'),
            // キャンセルの導線（7.19-8）。**画面の判定は案内のためだけのもの**であり、
            // 実行の可否は `ReservationService` がトランザクションの内側で決め直す。
            ...$this->cancelViewData($selected),
        ]);
    }

    /**
     * キャンセルの対象としてよい予約（`CancelsReservation`）。
     *
     * **到達の根拠は照合の結果（`matchedIds`）である**（4.3.18）。4.3.5 が非会員の
     * 導線を「予約照会で照合したうえで実行」と定めており、照合そのものが権限になる。
     */
    protected function cancellableReservation(): ?Reservation
    {
        return $this->selectedReservation();
    }

    /** 確認を出してよい予約のID（`CancelsReservation`）。明細は読み込まない。 */
    protected function cancellableReservationId(): ?int
    {
        if ($this->selectedId === null || ! in_array($this->selectedId, $this->matchedIds, strict: true)) {
            return null;
        }

        return $this->selectedId;
    }

    /**
     * 照会フォームの入力欄（4.3.5 の方式A・B）。方式によって並びと項目が変わる。
     *
     * **ビューではなくここで組み立てる。** Livewire のビューは先頭の `@php` ブロックを
     * 特別に扱うため、そこへ表示以外のロジックを置くと壊れやすい（既存の P-34・P-37 の
     * ビューも、先頭の `@php` は docblock だけに留めている）。
     *
     * `value` を持たせるのは、`wire:model` が初期描画で値を書き込まないためである。
     * これが無いと、予約確定メールのリンク（`?no=`）で埋めた予約番号が画面に出ない。
     *
     * @return array<int, array{property: string, value: string, type: string, autocomplete: string, inputmode: string|null, hint: string|null, placeholder: string|null}>
     */
    private function formFields(): array
    {
        $email = ['property' => 'email', 'value' => $this->email, 'type' => 'email', 'autocomplete' => 'email', 'inputmode' => 'email', 'hint' => null, 'placeholder' => null];

        if ($this->method === self::METHOD_NUMBER) {
            return [
                ['property' => 'reservationNo', 'value' => $this->reservationNo, 'type' => 'text', 'autocomplete' => 'off', 'inputmode' => 'numeric', 'hint' => 'reservation_no', 'placeholder' => 'reservation_no'],
                $email,
            ];
        }

        return [
            $email,
            ['property' => 'phone', 'value' => $this->phone, 'type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'numeric', 'hint' => 'phone', 'placeholder' => 'phone'],
            ['property' => 'screeningDate', 'value' => $this->screeningDate, 'type' => 'date', 'autocomplete' => 'off', 'inputmode' => null, 'hint' => null, 'placeholder' => null],
        ];
    }

    /**
     * 入力の検証規則（4.3.5 / 17.5.1-5）。
     *
     * **使わない方式の項目は `nullable` を付けず、規則ごと外す。** `nullable` が免除する
     * のは null のときだけであり、空でない不正な値には規則が走る。方式Aで誤った予約番号を
     * 入れたまま方式Bへ切り替えた利用者は、画面に無い項目の誤りを指摘され、消す手段を持た
     * ないまま行き止まりになる（照会のたびにレート制限の枠も減る）。
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $rules = [
            'method' => ['required', 'in:'.self::METHOD_NUMBER.','.self::METHOD_CONTACT],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];

        if ($this->method === self::METHOD_NUMBER) {
            // 予約番号は8桁の数字（4.3.5）。`normalizeInput()` がハイフンを落とした後の値を見る。
            $rules['reservationNo'] = ['required', 'string', 'digits:8'];

            return $rules;
        }

        $rules['phone'] = ['required', 'string', 'digits_between:10,11'];
        $rules['screeningDate'] = ['required', 'date_format:Y-m-d'];

        return $rules;
    }

    /**
     * 照会方式を切り替えたときに、前の方式で出した誤りの表示を消す。
     *
     * 入力値そのものは消さない。方式を行き来しても打ち直しにならないようにする。
     * 使わない項目は `rules()` から外れるため、残っていても照合には影響しない。
     */
    public function updatedMethod(): void
    {
        $this->resetErrorBag();
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'reservationNo.digits' => __('front.lookup.errors.reservation_no'),
            'phone.digits_between' => __('front.lookup.errors.phone'),
            'screeningDate.date_format' => __('front.lookup.errors.screening_date'),
        ];
    }

    /**
     * 「メールアドレスは必須です」のように項目名を日本語で出すための対応表（20.2）。
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = __('front.lookup.fields');

        return $fields;
    }

    /**
     * 照合の条件に合う予約のIDを、上映開始の新しい順に返す。
     *
     * **対象は `paid` と `cancelled` に限る。** `pending` は課金の直前に作られる行であり
     * （4.3.15）、利用者から見れば成立していない。`expired` は座席を確保できないまま終わった
     * 行であり、いずれも照会して示す内容を持たない。キャンセル済みを含めるのは、返金の状況を
     * 確認する経路が他に無いためである（4.3.16「キャンセル済みの内容は予約照会が扱う」）。
     *
     * @param  array<string, mixed>  $validated
     * @return Collection<int, int>
     */
    private function matchingReservations(array $validated): Collection
    {
        $isNumber = $validated['method'] === self::METHOD_NUMBER;

        /** @var string $email */
        $email = $validated['email'];
        $phone = $isNumber ? null : (string) $validated['phone'];

        // 非会員の予約。連絡先は `t_reservations` が直接持つ（4.3.6）。
        $guest = $this->lookupTarget()
            ->where('contact_type', ContactType::Guest)
            ->where('guest_email', $email);

        // 会員の予約。連絡先は `users` にあり、`guest_*` は null である。メールと電話を
        // 同じ行から照合するため経路を混ぜない。
        $users = User::query()->where('email', $email);

        if ($phone !== null) {
            $guest->where('guest_phone', $phone);
            $users->where('phone', $phone);
        }

        $member = $this->lookupTarget()
            ->where('contact_type', ContactType::Member)
            ->whereIn('user_id', $users->select('id'));

        foreach ([$guest, $member] as $side) {
            if ($isNumber) {
                /** @var string $reservationNo */
                $reservationNo = $validated['reservationNo'];
                $side->where('reservation_no', $reservationNo);

                continue;
            }

            /** @var string $date */
            $date = $validated['screeningDate'];

            $side->whereIn('screening_id', $this->screeningsOn($date)->select('id'));
        }

        /** @var Collection<int, int> $ids */
        $ids = $guest->select('id')
            // **`OR` ではなく `UNION` で束ねる**（6.1追記表「インデックスの棚卸し」が
            // 残した前提。A-11 の検索と同じ扱い。4.8.6追記表）。`OR` で書くと
            // `guest_email`（4.3.5 方式Bの駆動キー）の索引と `user_id` の索引を
            // どちらも活かせない。
            //
            // `union()` は呼び出し時点のバインドを取り込む一方、SQLは後段で
            // グローバルスコープ適用後に生成される。プレースホルダとバインドが
            // ずれないよう素のクエリを渡す（A-11 と同じ理由）。
            ->union($member->select('id')->toBase())
            ->pluck('id');

        return $this->orderedByScreening($ids);
    }

    /**
     * 照会の対象となる予約の母集合。`UNION` の両辺が同じ条件で始まるようにする。
     *
     * **状態の条件はモデルから引く**（`Reservation::visibleToCustomer()`。P-05・P-06 と
     * 同じ条件であり、片方だけの改定を許さない。4.3.8「条件の集約」）。
     *
     * @return Builder<Reservation>
     */
    private function lookupTarget(): Builder
    {
        return Reservation::query()->visibleToCustomer();
    }

    /**
     * 指定の日に始まる上映回（方式Bの「上映日」。4.3.5）。
     *
     * **`whereDate()` を使わず、開始日の境界で範囲を切る。** 列に関数を適用すると、
     * 索引が在っても使えない形になる（6.1追記表「インデックスの棚卸し」と同じ理由）。
     * 現状の `t_screenings` の索引は `(theater_id, starts_at)` であり左端が一致しない
     * ため、この書き換えだけで索引が効くようになるわけではない。**同じ列に対する
     * 条件の書き方を将来にわたって揃えておくための措置である。**
     *
     * 「上映日」は**開始日**で判定する（終了時刻は見ない）。
     *
     * @return Builder<Screening>
     */
    private function screeningsOn(string $date): Builder
    {
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay();

        return Screening::query()
            ->where('starts_at', '>=', $day)
            ->where('starts_at', '<', $day->addDay());
    }

    /**
     * 上映開始の新しい順に並べ替える（4.3.5「複数件が該当する場合」の一覧の並び）。
     *
     * `UNION` は並び順を保証しないため、束ねた後に並べ直す。件数は1人分の予約に限られる。
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function orderedByScreening(Collection $ids): Collection
    {
        // 方式Aは `reservation_no` が一意のため最大1件（4.3.5）。並べ替えるものが無い
        // 場合にクエリを足さない。
        if ($ids->count() < 2) {
            return $ids;
        }

        // 相関サブクエリで並べる。**テーブル名をリテラルで書かない**（6.1.1 の接頭辞規則）。
        // `qualifyColumn()` はモデルの `$table` から組み立てるため、表名を変えても追随する。
        $screeningId = (new Reservation)->qualifyColumn('screening_id');

        /** @var Collection<int, int> $ordered */
        $ordered = Reservation::query()
            ->whereIn('id', $ids->all())
            ->orderByDesc(Screening::query()->select('starts_at')->whereColumn('id', $screeningId))
            ->orderByDesc('id')
            ->pluck('id');

        return $ordered;
    }

    /**
     * 一覧に並べる候補（複数件が該当した場合。4.3.5）。
     *
     * @return Collection<int, Reservation>
     */
    private function candidates(): Collection
    {
        if (count($this->matchedIds) < 2) {
            return collect();
        }

        /** @var Collection<int, Reservation> $rows */
        $rows = Reservation::query()
            // 一覧が出すのは作品・劇場（館）・上映日時・予約番号・状態のみ（7.19）。
            // シアターは明細だけが使うため、ここでは読まない。
            ->with(['screening.booking.movie', 'screening.booking.cinema'])
            ->whereIn('id', $this->matchedIds)
            ->get()
            // `whereIn` は `matchedIds` の並びを保たないため、照合時の並び（上映開始の
            // 新しい順）へ戻す。
            ->sortBy(fn (Reservation $row): int => (int) array_search($row->id, $this->matchedIds, strict: true))
            ->values();

        return $rows;
    }

    /**
     * 選択中の予約。照合の範囲（`matchedIds`）に無いものは読まない。
     */
    private function selectedReservation(): ?Reservation
    {
        if ($this->selectedId === null || ! in_array($this->selectedId, $this->matchedIds, strict: true)) {
            return null;
        }

        return Reservation::query()
            ->with([
                'seats.seat',
                'seats.ticketType',
                'screening.theater',
                'screening.booking.movie',
                'screening.booking.format',
                'screening.booking.cinema',
            ])
            ->find($this->selectedId);
    }

    /** 入力の前後の空白を落とし、予約番号の区切りハイフンを外す（4.3.5「入力」）。 */
    private function normalizeInput(): void
    {
        foreach (['reservationNo', 'email', 'phone', 'screeningDate'] as $property) {
            $this->{$property} = (string) preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $this->{$property});
        }

        // 全角ハイフンや区切り記号も落とす。控えを見ながら打つ利用者が、表示形式
        // （`1234-5678`）のまま入力することを想定する。
        $this->reservationNo = (string) preg_replace('/[^0-9]/u', '', $this->reservationNo);
    }

    /**
     * 照会の回数を制限する（17.2.2）。方式A・B で共通の枠とする。
     *
     * **失敗理由を詳細に返さない**（17.2.2）。上限に達したことと待機時間のみを示す。
     */
    private function ensureIsNotRateLimited(): void
    {
        foreach ([[$this->minuteKey(), self::PER_MINUTE], [$this->hourKey(), self::PER_HOUR]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => __('front.lookup.errors.throttled', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ]);
            }
        }
    }

    private function minuteKey(): string
    {
        return 'lookup:m|'.request()->ip();
    }

    private function hourKey(): string
    {
        return 'lookup:h|'.request()->ip();
    }
}
