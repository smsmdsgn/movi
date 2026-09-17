<?php

namespace App\Livewire\Front\Reservation;

use App\Livewire\Front\Reservation\Concerns\GuardsReservationStep;
use App\Livewire\Front\Reservation\Concerns\ResolvesScreening;
use App\Livewire\Front\Reservation\Concerns\UsesReservationDraft;
use App\Models\Screening;
use App\Rules\FullWidthKatakana;
use App\Rules\FullWidthText;
use App\Services\SeatLockService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * お客様情報の入力（P-34、7.9 / 4.3.6）。1画面1コンポーネント（13.4.3）。
 *
 * **非会員だけが通る画面。** 会員は P-33 から券種選択（P-35）へ直行する（4.3.11）。
 * ログイン済みの利用者が本画面のURLへ到達した場合は入力を求めず P-35 へ送る。
 * 入力値は `t_reservations` ではなく `ReservationDraft`（セッション）へ置く。予約は
 * 決済完了時にしか作られないため（6.4.2）、それまでの持ち越し先が要る（4.3.12）。
 *
 * **入力値の検証だけでは先へ進めない。** 販売期間・座席の保持・利用規約への同意は
 * `GuardsReservationStep` が見る。フォームを正しく埋めても前提が崩れていれば進めない。
 */
class CustomerInfo extends Component
{
    use GuardsReservationStep;
    use ResolvesScreening;
    use UsesReservationDraft;

    /** 氏名（4.3.6「全角文字」）。 */
    public string $name = '';

    /** フリガナ（4.3.6「全角カタカナのみ」。A-11 の検索側と同じ文字集合）。 */
    public string $nameKana = '';

    /** 電話番号（4.3.6「ハイフンなしの半角数字」）。 */
    public string $phone = '';

    /** メールアドレス（4.3.6。予約確定メールの送信先および本人照合に使う）。 */
    public string $email = '';

    /** メールアドレスの確認用再入力（7.9）。控えが届かない事故を防ぐ。 */
    public string $emailConfirmation = '';

    /**
     * 前提（販売期間・座席・同意）が崩れていても、ここでは他画面へ飛ばさない。
     * `render()` が案内と復帰先を出す（P-33 と同じ扱い。4.3.12）。
     */
    public function mount(Screening $screening): void
    {
        $this->rememberScreening($screening);

        if (Auth::check()) {
            $this->forwardToTickets();

            return;
        }

        $this->fillFromDraft();
    }

    /**
     * 入力を確定し、券種選択（P-35、7.10）へ進む。
     *
     * 判定順は 前提 → 入力 とする。座席を失った利用者に入力の誤りを指摘しても、
     * 次の行動（座席の選び直し）につながらない（P-32 の `proceed()` と同じトーン）。
     */
    public function submit(SeatLockService $locks): void
    {
        // 別のタブでログインを済ませた後にこの画面の送信を押した場合。会員の予約に
        // `guest_*` を残さないよう、`mount()` と同じ扱いで券種選択へ送る
        // （`Identify::login()` が同じ経路に入れている判定と対になる）。
        if (Auth::check()) {
            $this->forwardToTickets();

            return;
        }

        if (! $this->canProceed($locks)) {
            return;
        }

        $this->normalizeInput();

        $validated = $this->validate();

        $screening = $this->screeningOnSale();

        // `canProceed()` が真なら販売期間内の上映回が存在する。PHPStan へ示すための分岐。
        if ($screening === null) {
            return;
        }

        $this->draft()->putGuest($screening, [
            'name' => $validated['name'],
            'name_kana' => $validated['nameKana'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
        ]);

        $this->forwardToTickets();
    }

    public function render(SeatLockService $locks): View
    {
        $status = $this->stepStatus($locks);

        return view('front.reservation.customer-form', $status + [
            'onSale' => $this->screeningOnSale() !== null,
            'canProceed' => $status['noticeKey'] === null,
            'identifyUrl' => route('front.reservation.identify', ['id' => $this->screeningId]),
        ]);
    }

    /**
     * 入力の検証規則（4.3.6 / 7.9）。
     *
     * 氏名・フリガナは共有のルールクラスを使う。フリガナは**登録側と検索側（A-11）が
     * 同じ文字集合でなければならない**（旧12章 残課題19）。
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', new FullWidthText('front.reservation.customer.errors.name')],
            'nameKana' => ['required', 'string', 'max:50', new FullWidthKatakana('front.reservation.customer.errors.name_kana')],
            // `digits_between` は数字以外を含む値を弾くため、半角数字の指定を兼ねる。
            // 国内の固定電話・携帯電話は10桁または11桁（4.3.6）。
            'phone' => ['required', 'string', 'digits_between:10,11'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'emailConfirmation' => ['required', 'string', 'same:email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'phone.digits_between' => __('front.reservation.customer.errors.phone'),
            'emailConfirmation.same' => __('front.reservation.customer.errors.email_mismatch'),
        ];
    }

    /**
     * 「氏名は必須です」のように項目名を日本語で出すための対応表（20.2）。
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = __('front.reservation.customer.fields');

        return $fields;
    }

    /**
     * 入力の前後の空白を落とす（A-11 の `search()` と同じ扱い）。
     *
     * **フリガナで特に効く。** A-11 のフリガナ検索は前方一致（`$term.'%'`）であり、
     * 先頭に空白を含めて登録された氏名は窓口から引き当てられない（4.8.5 / 4.3.12）。
     * `FullWidthKatakana` は姓名の区切りとして空白を許すため、文字集合の検証では落ちない。
     *
     * 語中の空白（`ギオン　タロウ` の区切り）は落とさない。姓名の境目であり、
     * 利用者が意図して入れたものと区別できない。
     */
    private function normalizeInput(): void
    {
        foreach (['name', 'nameKana', 'phone', 'email', 'emailConfirmation'] as $property) {
            $this->{$property} = (string) preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $this->{$property});
        }
    }

    /**
     * 記録済みの入力を書き戻す（P-35 から戻った場合など）。
     *
     * 確認用の再入力は書き戻さない。控えが届かない事故を防ぐための項目であり、
     * 自動で埋めると再入力を求める意味が失われる。
     */
    private function fillFromDraft(): void
    {
        $guest = $this->draft()->guest($this->screeningId);

        if ($guest === null) {
            return;
        }

        $this->name = $guest['name'];
        $this->nameKana = $guest['name_kana'];
        $this->phone = $guest['phone'];
        $this->email = $guest['email'];
    }

    /**
     * お客様情報（P-34）を前提としない。本画面がその入力を受け取る画面であり、
     * 前提に加えると初回の到達で自分自身へ送り返すことになる（4.3.14）。
     */
    protected function requiresGuestInput(): bool
    {
        return false;
    }

    /**
     * 券種選択（P-35、7.10）へ送る。会員・非会員のいずれの経路も P-35 で合流する（7.18）。
     */
    private function forwardToTickets(): void
    {
        $this->redirect(route('front.reservation.tickets', ['id' => $this->screeningId]), navigate: false);
    }
}
