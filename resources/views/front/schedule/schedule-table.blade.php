{{--
    上映スケジュール表（7.4、4.2.2）。館トップ（P-21）・上映スケジュール（P-22）・
    作品詳細（P-23）に埋め込む Livewire コンポーネントのビュー。

    render() から渡る変数:
    - $dates: 日付タブに並べる7日分（Collection<CarbonImmutable>）
    - $selectedDate: 選択中の日付（CarbonImmutable）
    - $blocks: 選択中の日付の作品ブロック（Collection<ScheduleBlock>）

    コンポーネントのプロパティ $cinema / $movie / $headingLevel も参照する。$movie が非nullの場合は
    作品詳細ページへの埋め込み（7.5-9）であり、自分自身へのリンクを避けるため
    作品名・ポスターを省き、規格と上映回のみを表示する。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \Carbon\CarbonImmutable> $dates */
    /** @var \Carbon\CarbonImmutable $selectedDate */
    /** @var \Illuminate\Support\Collection<int, \App\Services\ScheduleBlock> $blocks */
    $today = $dates->first();
    $weekdays = __('front.schedule.weekdays');
@endphp
<div wire:loading.class="opacity-50" wire:target="selectDate" class="transition-opacity">
    {{-- 日付の切替はサーバー往復を伴うボタン群であり、ARIA のタブパターン（tabpanel との対応）は組まない。選択状態は aria-pressed で示す。 --}}
    <div aria-label="{{ __('front.schedule.date_tabs_label') }}" class="flex overflow-x-auto border-b border-stone-300">
        @foreach ($dates as $day)
            @php
                $isSelected = $day->isSameDay($selectedDate);
                $isToday = $today !== null && $day->isSameDay($today);
                $label = $isToday
                    ? __('front.schedule.today').' '.$day->format('n/j')
                    : __('front.schedule.date_label', ['date' => $day->format('n/j'), 'weekday' => $weekdays[$day->dayOfWeek]]);
            @endphp
            <button
                type="button"
                wire:click="selectDate('{{ $day->toDateString() }}')"
                wire:key="date-{{ $day->toDateString() }}"
                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                class="shrink-0 border-b-2 px-4 py-2 text-sm tabular-nums {{ $isSelected ? 'border-brand font-bold' : 'border-transparent text-stone-600' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <p class="mt-4 text-sm text-stone-600">
        {{ __('front.schedule.date_heading', ['date' => __('front.schedule.date_label', ['date' => $selectedDate->format('n/j'), 'weekday' => $weekdays[$selectedDate->dayOfWeek]])]) }}
    </p>

    @if ($blocks->isEmpty())
        <p class="mt-4 text-sm text-stone-600">
            {{ $movie !== null ? __('front.schedule.empty_movie') : __('front.schedule.empty') }}
        </p>
    @endif

    @foreach ($blocks as $block)
        <article wire:key="block-{{ $block->movie->id }}" class="mt-4 border border-stone-300">
            <div class="flex gap-3 p-3">
                @if ($movie === null)
                    <div class="w-16 shrink-0 md:w-20">
                        <x-front.movie-poster :movie="$block->movie" />
                    </div>
                @endif
                <div class="min-w-0">
                    @if ($movie === null)
                        <{{ $headingLevel }} class="font-bold">
                            <a href="{{ route('front.movie.show', ['slug' => $cinema->slug, 'id' => $block->movie->id]) }}">
                                {{ $block->movie->title }}
                            </a>
                        </{{ $headingLevel }}>
                    @endif
                    <p class="mt-1 flex flex-wrap gap-1 text-xs">
                        @foreach ($block->formats as $format)
                            <span class="border border-stone-400 px-1">{{ $format->name }}</span>
                        @endforeach
                        <span>{{ __('front.schedule.runtime', ['minutes' => $block->movie->runtime_minutes]) }}</span>
                    </p>
                </div>
            </div>
            <ul class="grid grid-cols-3 gap-2 p-3 md:grid-cols-4 lg:grid-cols-6">
                @foreach ($block->slots as $slot)
                    <li wire:key="slot-{{ $slot->screening->id }}">
                        @php
                            $startsAtLabel = $slot->screening->starts_at->format('H:i');
                            $endsAtLabel = $slot->screening->ends_at->format('H:i');
                        @endphp
                        @if ($slot->availability->isSelectable())
                            <a
                                href="{{ route('front.reservation.seats', ['id' => $slot->screening->id]) }}"
                                data-availability="{{ $slot->availability->value }}"
                                aria-label="{{ __('front.schedule.select_screening', ['time' => $startsAtLabel]) }}"
                                class="block border border-stone-400 p-2 text-center hover:bg-stone-100"
                            >
                                <span class="block font-bold tabular-nums">{{ $startsAtLabel }}</span>
                                <span class="block text-xs tabular-nums text-stone-600">{{ __('front.schedule.ends_at', ['time' => $endsAtLabel]) }}</span>
                                <span class="block text-xs">{{ $slot->screening->theater->name }}</span>
                                @if ($block->hasMultipleFormats())
                                    <span class="block text-xs">{{ $slot->screening->booking->format->name }}</span>
                                @endif
                                <span class="block text-sm {{ $slot->availability === \App\Enums\SeatAvailability::Full ? 'font-bold text-red-700' : '' }}">
                                    {{ $slot->availability->symbol() }} {{ __($slot->availability->labelKey()) }}
                                </span>
                            </a>
                        @else
                            <div
                                data-availability="{{ $slot->availability->value }}"
                                aria-disabled="true"
                                class="block border border-stone-200 bg-stone-100 p-2 text-center text-stone-500"
                            >
                                <span class="block font-bold tabular-nums">{{ $startsAtLabel }}</span>
                                <span class="block text-xs tabular-nums">{{ __('front.schedule.ends_at', ['time' => $endsAtLabel]) }}</span>
                                <span class="block text-xs">{{ $slot->screening->theater->name }}</span>
                                @if ($block->hasMultipleFormats())
                                    <span class="block text-xs">{{ $slot->screening->booking->format->name }}</span>
                                @endif
                                <span class="block text-sm">{{ __($slot->availability->labelKey()) }}</span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </article>
    @endforeach

    <div class="mt-4 text-xs text-stone-600">
        <p>{{ __('front.schedule.availability_legend') }}
            @foreach (\App\Enums\SeatAvailability::cases() as $availability)
                <span class="ml-2">{{ $availability->symbol() }} {{ __($availability->labelKey()) }}</span>
            @endforeach
        </p>
        <p class="mt-1">{{ __('front.schedule.sales_note') }}</p>
    </div>
</div>
