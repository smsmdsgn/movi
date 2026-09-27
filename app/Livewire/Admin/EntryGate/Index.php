<?php

namespace App\Livewire\Admin\EntryGate;

use App\Models\Admin;
use App\Models\Cinema;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Screening;
use App\Services\EntryResult;
use App\Services\EntryService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 入場ゲート（A-16、4.6.3）。館内に設置した端末で常時開いておく画面。
 *
 * カメラでの読み取りはブラウザ側（`resources/js/entry-gate.js`、qr-scanner）が行い、
 * 読み取った入場コードを `admit()` へ渡す。Livewire のアクションは POST であり、
 * CSRF の対象にもなる（4.6.3「入場処理をGETリクエストで行わない」）。
 *
 * **端末の館は `Cinema::visibleTo()` で決める。** `gate`・`cinema-admin` は所属館に固定され、
 * `super-admin` は館セレクタで選んだ館の端末として動く（4.8.2「入場確認: 全館」。
 * 所属館を持たないため、選ぶまで読み取りを始めない）。
 *
 * 権限判定には `Gate::forUser($admin)` を用いる（13.4.2。A-10 と同じ理由）。
 * `AuthorizeAdminScreen` はフルページロードのみを保護するため、各アクションで判定する。
 */
class Index extends Component
{
    /**
     * QRコードによる照会の上限（1分あたり、端末のログインセッションごと。17.2.2）。
     * 入場コードは推測できない（4.6.2-1）ため、誤作動による連続送信を止める程度の値とする。
     */
    private const int QR_ATTEMPTS_PER_MINUTE = 60;

    /** 予約番号の手入力による照会の上限（1分あたり、端末のアカウントごと。17.2.2）。8桁の総当たりを防ぐ。 */
    private const int MANUAL_ATTEMPTS_PER_MINUTE = 10;

    public ?int $selectedCinemaId = null;

    /** 手入力モードの予約番号（4.6.3「手入力モード」）。 */
    public string $reservationNo = '';

    /**
     * 直近の判定結果の表示内容。null の間は読み取り待機（4.6.3 動作1）。
     *
     * **表示する文字列だけを持つ**（予約のIDやモデルを持たない）。3秒で消える一時的な
     * 表示であり、描き直しのたびに予約を引き直す必要が無い。クライアントから書き換え
     * られないよう `#[Locked]` とする。
     *
     * @var array{admitted: bool, message: string, name: ?string, startsAt: ?string, movie: ?string, theater: ?string, seats: ?string, count: ?int}|null
     */
    #[Locked]
    public ?array $result = null;

    /**
     * 判定の通し番号。同じ内容の結果が続いても表示を作り直し、3秒の計時をやり直すために
     * `wire:key` に用いる。
     */
    #[Locked]
    public int $resultSequence = 0;

    public function updatedSelectedCinemaId(): void
    {
        $this->dismiss();
    }

    /**
     * QRコードから読み取った入場コードで入場を処理する（4.6.3 動作2〜5）。
     */
    public function admit(EntryService $entries, string $entryCode): void
    {
        Gate::forUser($this->currentAdmin())->authorize('checkIn', Reservation::class);

        $cinema = $this->targetCinema();

        if ($cinema === null) {
            return;
        }

        $waitSeconds = $this->throttle('qr:'.session()->getId(), self::QR_ATTEMPTS_PER_MINUTE);

        if ($waitSeconds !== null) {
            $this->showThrottled($waitSeconds);

            return;
        }

        $this->showResult($entries->admitByEntryCode($cinema, $entryCode));
    }

    /**
     * 予約番号で入場を処理する（4.6.3「手入力モード」）。判定と表示は QR と同じ。
     */
    public function admitByReservationNo(EntryService $entries): void
    {
        Gate::forUser($this->currentAdmin())->authorize('checkIn', Reservation::class);

        $cinema = $this->targetCinema();

        if ($cinema === null) {
            return;
        }

        $this->reservationNo = trim($this->reservationNo);

        $this->validate(
            // 4.3.5: 8桁の数字。ハイフンの有無を問わず受け付ける（A-11 と同じ）。
            ['reservationNo' => ['required', 'string', 'regex:/\A\d{4}-?\d{4}\z/']],
            ['reservationNo.regex' => __('admin.gate.errors.reservation_no_digits')],
            ['reservationNo' => __('admin.gate.fields.reservation_no')],
        );

        // 形式を満たした入力だけを数える（照会に至らない入力誤りで上限を消費させない）。
        $waitSeconds = $this->throttle('manual', self::MANUAL_ATTEMPTS_PER_MINUTE);

        if ($waitSeconds !== null) {
            $this->addError('reservationNo', __('admin.gate.errors.throttled', ['seconds' => $waitSeconds]));

            return;
        }

        $this->showResult($entries->admitByReservationNo($cinema, $this->reservationNo));
        $this->reset('reservationNo');
    }

    /**
     * 判定結果を消し、読み取り待機へ戻る（4.6.3 動作6。画面側が表示の3秒後に呼ぶ）。
     *
     * `$sequence` は画面側が計時を始めた時点の `resultSequence`。表示中に次の判定が
     * 届いた場合（手入力の送信など）、古い計時が新しい結果を3秒に満たずに消さないよう、
     * 一致するときだけ消す。
     */
    public function dismiss(?int $sequence = null): void
    {
        if ($sequence !== null && $sequence !== $this->resultSequence) {
            return;
        }

        $this->result = null;
    }

    /**
     * 照会の回数を数える（17.2.2）。上限に達していれば待機秒数を返し、数えない。
     *
     * **IPではなくアカウントで数える。** 館内の複数の端末は同じIPから接続することが多く、
     * IPで数えると端末どうしが上限を分け合い、開場時の入場が止まる。
     * QR はさらにログインセッションで分ける（1つの gate アカウントを複数の端末で使っても
     * 上限を分け合わない）。手入力は総当たりを防ぐ目的のため、アカウント単位のままとする
     * （セッションで分けると、ログインし直すたびに上限が戻る）。
     */
    private function throttle(string $method, int $maxAttempts): ?int
    {
        $key = 'entry-gate:'.$method.':'.$this->currentAdmin()->id;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return RateLimiter::availableIn($key);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    /** 上限に達した旨を、判定結果と同じ全面表示（不可の色）で示す。予約には触れていない。 */
    private function showThrottled(int $waitSeconds): void
    {
        $this->result = [
            'admitted' => false,
            'message' => __('admin.gate.errors.throttled', ['seconds' => $waitSeconds]),
            'name' => null,
            'startsAt' => null,
            'movie' => null,
            'theater' => null,
            'seats' => null,
            'count' => null,
        ];
        $this->resultSequence++;
    }

    private function showResult(EntryResult $entry): void
    {
        $reservation = $entry->reservation;
        $admitted = $entry->outcome->isAdmitted();
        $screening = $reservation?->screening;

        // 4.6.3「表示項目」。入場可は 作品名／上映開始時刻／シアター／座席／枚数、
        // 入場不可は 拒否理由／予約者名／上映開始時刻。予約を示せない拒否（他館・該当なし）は
        // 理由のみとする（`EntryOutcome::revealsReservation()`）。
        $seats = $admitted && $reservation !== null ? $reservation->seatsInGridOrder() : null;

        $this->result = [
            'admitted' => $admitted,
            // `too_early` のみ `:minutes` を含む（4.6.4-3）。他の文言は未使用の置換
            // 引数を無視するため、呼び分けを設けない。
            'message' => __($entry->outcome->messageKey(), ['minutes' => Screening::ENTRY_OPENS_MINUTES_BEFORE]),
            'name' => ! $admitted ? $reservation?->displayName() : null,
            'startsAt' => $screening?->starts_at->format('n/j H:i'),
            'movie' => $admitted ? $screening?->booking->movie->title : null,
            'theater' => $admitted ? $screening?->theater->name : null,
            'seats' => $seats?->map(fn (ReservationSeat $row): string => $row->seat->displayName())->implode('、'),
            'count' => $seats?->count(),
        ];
        $this->resultSequence++;
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    private function canSelectCinema(): bool
    {
        return Gate::forUser($this->currentAdmin())->allows('viewAllCinemas', Cinema::class);
    }

    /**
     * 端末の館。`gate`・`cinema-admin` は所属館、`super-admin` は選択した館（未選択なら null）。
     * 可視範囲の外の館は選べない（`Cinema::visibleTo()`）。
     */
    private function targetCinema(): ?Cinema
    {
        $query = Cinema::visibleTo($this->currentAdmin());

        if (! $this->canSelectCinema()) {
            return $query->first();
        }

        return $this->selectedCinemaId === null ? null : $query->find($this->selectedCinemaId);
    }

    public function render(): View
    {
        return view('admin.entry-gate.index', [
            'cinema' => $this->targetCinema(),
            'canSelectCinema' => $this->canSelectCinema(),
            'cinemas' => $this->canSelectCinema()
                ? Cinema::visibleTo($this->currentAdmin())->orderBy('id')->get()
                : new Collection,
        ])->layout('layouts.admin', ['title' => __('admin.gate.title')]);
    }
}
