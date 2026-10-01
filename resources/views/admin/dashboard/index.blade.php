<div class="flex flex-col gap-6">
    <flux:heading level="1">{{ __('admin.dashboard.title') }}</flux:heading>

    <flux:text>
        {{ __('admin.dashboard.greeting', ['name' => auth('admin')->user()->name]) }}
    </flux:text>

    @if ($canSelectCinema)
        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="selectedCinemaId" :label="__('admin.dashboard.cinema')" class="w-56">
                <flux:select.option value="">{{ __('admin.common.all_cinemas') }}</flux:select.option>
                @foreach ($cinemas as $cinema)
                    <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    <section class="flex flex-col gap-3">
        <flux:heading level="2">{{ __('admin.dashboard.today_heading') }}</flux:heading>

        <dl class="grid grid-cols-2 gap-4 md:grid-cols-3">
            <div>
                <dt><flux:text>{{ __('admin.dashboard.screenings_count') }}</flux:text></dt>
                <dd><flux:heading size="xl">{{ $summary['screenings'] }}</flux:heading></dd>
            </div>
            <div>
                <dt><flux:text>{{ __('admin.dashboard.reservations_count') }}</flux:text></dt>
                <dd><flux:heading size="xl">{{ $summary['reservations'] }}</flux:heading></dd>
            </div>
            <div>
                <dt><flux:text>{{ __('admin.dashboard.seats_count') }}</flux:text></dt>
                <dd><flux:heading size="xl">{{ $summary['seats'] }}</flux:heading></dd>
            </div>
            <div>
                <dt><flux:text>{{ __('admin.dashboard.checked_in_count') }}</flux:text></dt>
                <dd><flux:heading size="xl">{{ $summary['checkedIn'] }}</flux:heading></dd>
            </div>
            <div>
                <dt><flux:text>{{ __('admin.dashboard.check_in_rate') }}</flux:text></dt>
                <dd>
                    <flux:heading size="xl">
                        {{ $summary['checkInRate'] === null
                            ? __('admin.dashboard.check_in_rate_none')
                            : __('admin.dashboard.check_in_rate_value', ['rate' => $summary['checkInRate']]) }}
                    </flux:heading>
                </dd>
            </div>
            <div>
                <dt><flux:text>{{ __('admin.dashboard.unpublished_posts') }}</flux:text></dt>
                <dd><flux:heading size="xl">{{ $unpublishedPostCount }}</flux:heading></dd>
            </div>
        </dl>
    </section>

    <section class="flex flex-col gap-3">
        <flux:heading level="2">{{ __('admin.dashboard.trend_heading') }}</flux:heading>

        @php($trendMax = max(array_column($trend, 'count')))

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('admin.dashboard.trend_date') }}</flux:table.column>
                <flux:table.column>{{ __('admin.dashboard.trend_count') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($trend as $row)
                    <flux:table.row :key="$row['date']->toDateString()">
                        <flux:table.cell>{{ $row['date']->format('Y/m/d') }}</flux:table.cell>
                        <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                        <flux:table.cell class="w-1/2">
                            <div aria-hidden="true" class="h-3 rounded bg-blue-500" style="width: {{ $trendMax > 0 ? (int) round($row['count'] / $trendMax * 100) : 0 }}%"></div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="flex flex-col gap-3">
        <flux:heading level="2">{{ __('admin.dashboard.list_heading') }}</flux:heading>

        @if ($screenings->isEmpty())
            <flux:text>{{ __('admin.dashboard.list_empty') }}</flux:text>
        @else
            <flux:table :paginate="$screenings">
                <flux:table.columns>
                    <flux:table.column>{{ __('admin.dashboard.starts_at') }}</flux:table.column>
                    @if ($canSelectCinema)
                        <flux:table.column>{{ __('admin.dashboard.cinema') }}</flux:table.column>
                    @endif
                    <flux:table.column>{{ __('admin.dashboard.theater') }}</flux:table.column>
                    <flux:table.column>{{ __('admin.dashboard.movie') }}</flux:table.column>
                    <flux:table.column>{{ __('admin.dashboard.seats_ratio_label') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($screenings as $screening)
                        <flux:table.row :key="$screening->id">
                            <flux:table.cell>{{ $screening->starts_at->format('H:i') }}</flux:table.cell>
                            @if ($canSelectCinema)
                                <flux:table.cell>{{ $screening->booking->cinema->name }}</flux:table.cell>
                            @endif
                            <flux:table.cell>{{ $screening->theater->name }}</flux:table.cell>
                            <flux:table.cell>{{ $screening->booking->movie->title }}</flux:table.cell>
                            <flux:table.cell>
                                {{ __('admin.dashboard.seats_ratio', [
                                    'booked' => $screening->booked_seats_count,
                                    'total' => $screening->theater->available_seats_count,
                                ]) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>
</div>
