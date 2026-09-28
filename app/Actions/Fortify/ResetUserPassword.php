<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // ハッシュ化してから代入する。`hashed` キャストはハッシュ済みに見える入力
        // （bcrypt 形式の文字列）をそのまま保存するため（旧12章 残課題20）。
        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
