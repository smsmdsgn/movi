<?php

namespace App\Livewire\Front\Contact;

use App\Enums\ContactCategory;
use App\Services\TurnstileService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * お問い合わせフォーム（P-14、4.9.2）。1画面1コンポーネント（13.4.3）。入力を伴うため
 * Livewire とする（front-ui）。
 *
 * **ダミー実装。** 入力内容の検証のみ行い、メール送信・DB保存・ログ出力は一切しない
 * （4.9.2「メール送信およびDB保存は行わない」/ 17.4.3「連絡先をログへ出力しない」）。
 * 入力値そのものもセッションへ残さず、送信完了画面（P-15）へは
 * 「送信済みである」ことを示すフラグのみを渡す。
 *
 * **ボット対策（Cloudflare Turnstile、4.9.2 / 4.9.8）。** `turnstileToken` の siteverify
 * への照会は、入力項目の検証（`validate()`）を**通った後**に行う。トークンは1回限りで
 * あり、入力の誤りで差し戻すたびに消費させないため（4.9.8「構成」）。
 */
class Index extends Component
{
    /** 氏名（4.9.2）。 */
    public string $name = '';

    /** メールアドレス（4.9.2）。 */
    public string $email = '';

    /** お問い合わせの種別（4.9.2）。 */
    public string $category = '';

    /** お問い合わせ本文（4.9.2）。 */
    public string $body = '';

    /**
     * Turnstile のウィジェットが発行したトークン（4.9.8）。ブラウザが Alpine から
     * `$wire.$set()` で入れる。**サーバーが siteverify へ照会するまで信用しない。**
     */
    public string $turnstileToken = '';

    /**
     * 入力を検証し、送信完了画面（P-15）へ進む（4.9.2）。
     *
     * **入力内容は保存も持ち回りもしない。** セッションには「送信済みである」ことを示す
     * フラグのみをフラッシュする（4.9.6 / 17.4.3）。
     *
     * 判定順は 入力 → Turnstile とする（4.9.8「構成」）。キーが揃っていない場合は、
     * 利用者の操作では解消しないため入力の検証もせず、送信できない旨だけを返す
     * （4.9.8「キー未設定時」）。
     */
    public function submit(TurnstileService $turnstile): void
    {
        if (! $turnstile->isConfigured()) {
            $this->addError('turnstileToken', __('front.contact.turnstile.unavailable'));

            return;
        }

        $this->validate();

        if (! $turnstile->verify($this->turnstileToken, request()->ip())) {
            // トークンは1回限りのため、失敗したものを持ち越さずウィジェットをやり直させる
            // （4.9.8「失敗時の扱い」）。
            $this->turnstileToken = '';
            $this->dispatch('turnstile-reset');
            $this->addError('turnstileToken', __('front.contact.errors.turnstile_failed'));

            return;
        }

        session()->flash('contact.submitted', true);

        $this->redirectRoute('front.contact.complete', navigate: false);
    }

    public function render(TurnstileService $turnstile): View
    {
        return view('front.contact.contact-form', [
            'categories' => ContactCategory::cases(),
            // ウィジェットの初期化に使う公開情報（ブラウザへ渡す前提の値。17.9-1）。
            // キーが片方でも欠けていればウィジェットを描画せず案内のみを表示する
            // （4.9.8「キー未設定時」）。
            'siteKey' => $turnstile->isConfigured() ? $turnstile->siteKey() : null,
        ]);
    }

    /**
     * 入力の検証規則（4.9.2 / 13.4.4）。`turnstileToken` はCloudflareの仕様上
     * 最大2048文字（4.9.8「入力の検証規則」）。
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'category' => ['required', Rule::enum(ContactCategory::class)],
            'body' => ['required', 'string', 'max:2000'],
            'turnstileToken' => ['required', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'turnstileToken.required' => __('front.contact.errors.turnstile_required'),
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
        $fields = __('front.contact.fields');

        return $fields;
    }
}
