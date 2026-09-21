<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $name_kana
 * @property string $email
 * @property string $phone
 * @property bool $is_newsletter_subscribed
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'name_kana', 'email', 'phone', 'is_newsletter_subscribed', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'users';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_newsletter_subscribed' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'user_id');
    }

    /**
     * @return HasMany<Stamp, $this>
     */
    public function stamps(): HasMany
    {
        return $this->hasMany(Stamp::class, 'user_id');
    }

    /**
     * @return HasMany<FreeTicket, $this>
     */
    public function freeTickets(): HasMany
    {
        return $this->hasMany(FreeTicket::class, 'user_id');
    }

    /**
     * まだ無料鑑賞券へ交換していないスタンプ（4.5.1-2）。
     *
     * **カウンタ列を持たず、行数を集計して求める**（4.5.1 実装方針）。交換済みの
     * スタンプは `free_ticket_id` に発行した券が入るため、「0個にリセットする」は
     * 行の削除ではなく交換先の記録で表す（履歴が残る）。
     *
     * @return HasMany<Stamp, $this>
     */
    public function unexchangedStamps(): HasMany
    {
        return $this->stamps()->whereNull('free_ticket_id');
    }
}
