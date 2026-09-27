<?php

namespace App\Livewire\Front\Contact;

use App\Enums\ContactCategory;
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
     * 入力を検証し、送信完了画面（P-15）へ進む（4.9.2）。
     *
     * **入力内容は保存も持ち回りもしない。** セッションには「送信済みである」ことを示す
     * フラグのみをフラッシュする（4.9.6 / 17.4.3）。
     */
    public function submit(): void
    {
        $this->validate();

        session()->flash('contact.submitted', true);

        $this->redirectRoute('front.contact.complete', navigate: false);
    }

    public function render(): View
    {
        return view('front.contact.contact-form', [
            'categories' => ContactCategory::cases(),
        ]);
    }

    /**
     * 入力の検証規則（4.9.2 / 13.4.4）。
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
