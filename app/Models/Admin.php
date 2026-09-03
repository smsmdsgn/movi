<?php

namespace App\Models;

use App\Enums\AdminRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * @property int $id
 * @property string $login_id
 * @property string $password
 * @property string $name
 * @property AdminRole $role
 * @property int|null $cinema_id
 * @property bool $is_active
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['login_id', 'password', 'name', 'role', 'cinema_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class Admin extends Authenticatable
{
    protected $table = 'm_admins';

    protected function casts(): array
    {
        return [
            'role' => AdminRole::class,
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Cinema, $this>
     */
    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class, 'cinema_id');
    }

    /**
     * ログイン成功時・ログイン済みでの `/admin/login` 再訪問時の遷移先。
     * `view-admin-screen` Gate（AppServiceProvider）で到達可能な最初の画面を返す。
     * `gate` ロールはダッシュボードへ到達できないため、この解決が無いと
     * ログイン直後に403へ遷移してしまう（17.1.3）。
     */
    public function landingRouteName(): string
    {
        foreach (['admin.dashboard', 'admin.gate.index'] as $routeName) {
            if (Gate::forUser($this)->allows('view-admin-screen', $routeName)) {
                return $routeName;
            }
        }

        abort(403);
    }
}
