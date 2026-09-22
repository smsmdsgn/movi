<?php

namespace App\Http\Controllers\Front;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\FreeTicket;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;

/**
 * マイページ（P-05、7.14）。会員専用（`auth` ミドルウェア）。
 *
 * **操作を伴わないため Livewire を用いない**（13.4.3 / front-ui）。過去の予約の
 * ページネーションはクエリ文字列（`?page=`）によるページ遷移であり、コンポーネントの
 * 状態ではない。
 *
 * **館非依存ページ（P-05〜P-20）のため館の解決を行わない。** ヘッダーが
 * `CurrentCinemaService` から自前で解決する（`LookupController` と同じ扱い。4.3.17）。
 */
class MyPageController extends Controller
{
    /** 過去の予約の1ページあたりの件数（7.14 構成要素3）。 */
    private const int HISTORY_PER_PAGE = 10;

    public function __invoke(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $now = Date::now();

        return view('front.mypage.index', [
            // 7.14 構成要素1。スタンプ数は行数の集計で求める（4.5.1 実装方針）。
            'stampCount' => $user->unexchangedStamps()->count(),
            'stampsPerTicket' => FreeTicket::STAMPS_PER_TICKET,
            'freeTickets' => $this->availableFreeTickets($user, $now),
            // 7.14 構成要素2・3
            'upcoming' => $this->upcomingReservations($user, $now),
            'history' => $this->pastReservations($user, $now),
        ]);
    }

    /**
     * 使える無料鑑賞券（4.5.2-4 / 7.14 構成要素1「保有枚数と有効期限」）。
     *
     * 使用状態は `t_reservations.active_free_ticket_id` から導出する
     * （`FreeTicket::available()`。6.1追記表「無料鑑賞券の使用状態の管理方式」）。
     *
     * @return Collection<int, FreeTicket>
     */
    private function availableFreeTickets(User $user, CarbonImmutable $now): Collection
    {
        // 所有者の絞り込みは関連に委ねる（`user_id` の条件を画面側に書き直さない。17.15 T-11）。
        return $user->freeTickets()
            ->available($now)
            // 期限の近いものから示す。使い忘れを防ぐ並びにする。
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * これからの予約（7.14 構成要素2）。
     *
     * **境界は上映終了時刻とする。** 開始済みでも終了までは入場できるため（4.6.4-3）、
     * まだ使えるチケットを「過去」に送らない。キャンセル済みは含めない（これから
     * 使うものではない）。
     *
     * @return Collection<int, Reservation>
     */
    private function upcomingReservations(User $user, CarbonImmutable $now): Collection
    {
        return $this->baseQuery($user)
            ->where('status', ReservationStatus::Paid)
            ->whereIn('screening_id', Screening::query()->where('ends_at', '>', $now)->select('id'))
            ->orderBy(Screening::query()->select('starts_at')->whereColumn('id', $this->screeningIdColumn()))
            ->get();
    }

    /**
     * 過去の予約（7.14 構成要素3）。
     *
     * 上映が終わった予約と、**キャンセル済みの予約（上映日時を問わず）**を含める。
     * キャンセル済みは「これから使うもの」ではないため、上映前であっても履歴側に置く。
     *
     * @return LengthAwarePaginator<int, Reservation>
     */
    private function pastReservations(User $user, CarbonImmutable $now): LengthAwarePaginator
    {
        return $this->baseQuery($user)
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->where('status', ReservationStatus::Cancelled)
                    ->orWhereIn('screening_id', Screening::query()->where('ends_at', '<=', $now)->select('id'));
            })
            ->orderByDesc(Screening::query()->select('starts_at')->whereColumn('id', $this->screeningIdColumn()))
            ->orderByDesc('id')
            ->paginate(self::HISTORY_PER_PAGE);
    }

    /**
     * 一覧に共通の絞り込みと読み込み。
     *
     * **対象は `paid` と `cancelled` に限る**（`Reservation::visibleToCustomer()`。
     * 予約照会 P-07・予約詳細 P-06 と同じ条件をモデルから引く。4.3.8「条件の集約」/
     * 4.3.17）。
     *
     * @return Builder<Reservation>
     */
    private function baseQuery(User $user): Builder
    {
        // 所有者の絞り込みは関連に委ねる（`user_id` の条件を画面側に書き直さない。
        // 17.15 T-11）。`getQuery()` は関連が付けた外部キーの条件を保ったまま
        // クエリビルダを返す。
        return $user->reservations()->getQuery()
            ->visibleToCustomer()
            // 一覧が触れる関連（`preventLazyLoading`）。座席は枚数だけを出すため
            // `withCount()` で足りる。
            ->with(['screening.booking.movie', 'screening.booking.cinema'])
            ->withCount('seats');
    }

    /**
     * 相関サブクエリで参照する `t_reservations.screening_id`。
     *
     * **テーブル名をリテラルで書かない**（6.1.1。`qualifyColumn()` はモデルの `$table`
     * から組み立てるため、表名を変えても追随する）。
     */
    private function screeningIdColumn(): string
    {
        return (new Reservation)->qualifyColumn('screening_id');
    }
}
