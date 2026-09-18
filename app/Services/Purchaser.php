<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Models\User;

/**
 * 予約の購入者（4.3.2 / 4.3.6）。会員と非会員の差を `ReservationService` の外で吸収する。
 *
 * `t_reservations` は会員を `user_id`、非会員を `guest_*` で持つ（6.1.2）。どちらであるかの
 * 分岐を確定処理の中に散らさないため、列の値の組み立てを本クラスに寄せる。
 *
 * @phpstan-import-type GuestInput from ReservationDraft
 */
final readonly class Purchaser
{
    /**
     * @param  GuestInput|null  $guest
     */
    private function __construct(
        public ContactType $contactType,
        public ?int $userId,
        public ?array $guest,
    ) {}

    public static function member(User $user): self
    {
        return new self(ContactType::Member, $user->id, null);
    }

    /**
     * @param  GuestInput  $guest  P-34 で入力し `ReservationDraft` が保持している内容
     */
    public static function guest(array $guest): self
    {
        return new self(ContactType::Guest, null, $guest);
    }

    /**
     * `t_reservations` に書き込む購入者の列（6.1.2）。
     *
     * 非会員の連絡先は `guest_*` に保存する。会員は `users` 側を参照するため複製しない
     * （同じ情報を2箇所に持つと、会員情報の変更で食い違う）。
     *
     * @return array<string, string|int|null>
     */
    public function attributes(): array
    {
        return [
            'contact_type' => $this->contactType->value,
            'user_id' => $this->userId,
            'guest_name' => $this->guest['name'] ?? null,
            'guest_name_kana' => $this->guest['name_kana'] ?? null,
            'guest_email' => $this->guest['email'] ?? null,
            'guest_phone' => $this->guest['phone'] ?? null,
        ];
    }
}
