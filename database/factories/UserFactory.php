<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * フリガナ生成用の姓・名（カタカナ）。
     * fake()->kanaName() は ja_JP ロケール限定のプロバイダメソッドで
     * PHPStan の Faker\Generator スタブにないため、固定リストから組み立てる。
     *
     * @var array<int, string>
     */
    private const KANA_LAST_NAMES = ['サトウ', 'スズキ', 'タカハシ', 'タナカ', 'ワタナベ', 'イトウ', 'ヤマモト', 'ナカムラ'];

    /**
     * @var array<int, string>
     */
    private const KANA_FIRST_NAMES = ['ハルト', 'ユウト', 'ソウタ', 'ミナト', 'ヒナタ', 'サクラ', 'ユイ', 'アオイ'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'name_kana' => fake()->randomElement(self::KANA_LAST_NAMES).fake()->randomElement(self::KANA_FIRST_NAMES),
            'email' => fake()->unique()->safeEmail(),
            'phone' => preg_replace('/\D/', '', fake()->phoneNumber()) ?? '0312345678',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     *
     * 二要素認証は本プロジェクトの対象外（design.md 2.5.1）であり、
     * `users` に two_factor_* 列も存在しない。スターターキット標準の
     * テストが参照するための no-op スタブとして残す。
     */
    public function withTwoFactor(): static
    {
        return $this;
    }
}
