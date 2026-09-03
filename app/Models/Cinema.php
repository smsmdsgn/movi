<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $concept
 * @property string $address
 * @property string $phone
 * @property string $business_hours
 * @property string $facility_info
 * @property string $access_note
 * @property string $map_embed_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['slug', 'name', 'concept', 'address', 'phone', 'business_hours', 'facility_info', 'access_note', 'map_embed_url'])]
class Cinema extends Model
{
    /**
     * 館が未選択の場合の既定値（4.1.3-2）。祇園ムビ（本館、4.1.1）の slug。
     */
    public const string DEFAULT_SLUG = 'gion';

    /**
     * 選択中の館の slug を保持するセッション・Cookie のキー（4.1.3-1）。
     * `ResolveCinema` と `App\Services\CurrentCinemaService` の双方が参照する。
     */
    public const string SESSION_KEY = 'cinema_slug';

    /**
     * slug の形式（4.1.3-6）。ルート制約（routes/web.php の `{slug}`）と
     * A-03 の作成・編集バリデーションで同一のものを用いる。
     * `preg_quote()` を経由しない生のバリデーションルール文字列に埋め込むため、
     * `|`・`/` は含めないこと（含めると `ValidationRuleParser` の区切り文字と衝突する）。
     */
    public const string SLUG_REGEX = '[a-z]+(?:-[a-z]+)*';

    protected $table = 'm_cinemas';

    /**
     * A-03（館マスタ管理）の一覧取得範囲を絞り込む（4.8.2）。
     *
     * `Theater`等が使う `CinemaScope`（グローバルスコープ、`cinema_id`列で絞り込む）は
     * 適用しない。`Cinema`自体は`cinema_id`外部キーを持たない（自身が館そのものである）
     * ことに加え、`CinemaScope`は`admin`ガードのセッションの有無のみで発火するため、
     * グローバルスコープとして適用すると顧客側（`ResolveCinema`のslug解決、
     * `CurrentCinemaService`のフォールバック等）が管理者と同一ブラウザで壊れる
     * （4.8.6追記表の`CinemaScope`の既知の制約）。ローカルスコープとして
     * 呼び出し元（A-03）が明示的に適用することで、この波及を避ける。
     *
     * @param  Builder<Cinema>  $query
     * @return Builder<Cinema>
     */
    #[Scope]
    protected function visibleTo(Builder $query, Admin $admin): Builder
    {
        if (Gate::forUser($admin)->allows('viewAllCinemas', self::class)) {
            return $query;
        }

        // cinema_id が未設定の場合に空の一覧を返すと設定漏れの発見が遅れるため、
        // CinemaScope（app/Models/Scopes/CinemaScope.php）と同様に403とする。
        abort_if($admin->cinema_id === null, 403, '所属館が設定されていない管理者です。');

        return $query->where('id', $admin->cinema_id);
    }

    /**
     * @return HasMany<Theater, $this>
     */
    public function theaters(): HasMany
    {
        return $this->hasMany(Theater::class, 'cinema_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'cinema_id');
    }

    /**
     * @return HasMany<Admin, $this>
     */
    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class, 'cinema_id');
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'cinema_id');
    }

    /**
     * @return HasMany<Banner, $this>
     */
    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class, 'cinema_id');
    }
}
