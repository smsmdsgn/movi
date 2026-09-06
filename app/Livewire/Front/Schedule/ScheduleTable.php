<?php

namespace App\Livewire\Front\Schedule;

use App\Models\Cinema;
use App\Models\Movie;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 上映スケジュール表（7.4）。館トップ（P-21）・上映スケジュール（P-22）・作品詳細（P-23）に
 * 埋め込み、日付タブの切替のみを Livewire の状態として持つ（13.4.3）。
 *
 * 館と作品は `mount()` の引数で受けてプロパティに保持する。`/livewire/update` は
 * `ResolveCinema` を通らずコンテナに館がバインドされないため、`app(Cinema::class)` を
 * 呼ばない（4.1.3追記表 / cinema-scope スキル）。
 */
class ScheduleTable extends Component
{
    #[Locked]
    public Cinema $cinema;

    /** 作品詳細（P-23）では当該作品の回のみに絞る（7.5-9）。 */
    #[Locked]
    public ?Movie $movie = null;

    /**
     * 作品ブロックの見出し要素。埋め込み先の見出し階層を飛ばさない（19.3-7）ため
     * 呼び出し側が指定する。P-21 は h2 の帯の下に置くため h3、P-22 は h1 直下のため h2。
     */
    #[Locked]
    public string $headingLevel = 'h3';

    /** 選択中の日付（`Y-m-d`）。既定は当日（4.2.2-1）。 */
    public string $date = '';

    public function mount(Cinema $cinema, ?Movie $movie = null, string $headingLevel = 'h3'): void
    {
        $this->cinema = $cinema;
        $this->movie = $movie;
        $this->headingLevel = in_array($headingLevel, ['h2', 'h3'], true) ? $headingLevel : 'h3';
        $this->date = Date::now()->toDateString();
    }

    /**
     * 日付タブの切替。表示範囲（当日から7日分）以外の値は許可値の照合で拒否する（17.5.1-2）。
     * アクションの引数を検証するため `rules()`（13.4.4）ではなく `Validator` を用いる（4.2.3追記表）。
     */
    public function selectDate(string $date): void
    {
        Validator::make(
            ['date' => $date],
            ['date' => ['required', Rule::in($this->dateOptions()->map(fn (CarbonImmutable $day): string => $day->toDateString())->all())]],
        )->validate();

        $this->date = $date;
    }

    public function render(ScheduleService $schedule): View
    {
        $dates = $this->dateOptions();
        $today = $dates->first();

        // 日付をまたいで開いたままの画面から古い日付が送られた場合、および
        // `$date` をクライアントから直接書き換えられた場合は当日へ戻す（許可値以外を表示しない）。
        $selected = $dates->first(fn (CarbonImmutable $day): bool => $day->toDateString() === $this->date) ?? $today;
        $this->date = $selected->toDateString();

        return view('front.schedule.schedule-table', [
            'dates' => $dates,
            'selectedDate' => $selected,
            'blocks' => $schedule->blocksOn($this->cinema, $selected, $this->movie),
        ]);
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    private function dateOptions(): Collection
    {
        return app(ScheduleService::class)->displayDates();
    }
}
