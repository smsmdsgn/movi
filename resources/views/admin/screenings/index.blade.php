@php
    $currentAdmin = auth('admin')->user();
    $canSelectCinema = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('viewAllCinemas', \App\Models\Cinema::class);
    $canEditAny = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('updateAny', \App\Models\Screening::class);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('admin.screening.title') }}</flux:heading>

        <div class="flex items-center gap-2">
            @if ($canSelectCinema)
                <flux:select wire:model.live="selectedCinemaId" class="w-56">
                    <flux:select.option value="">{{ __('admin.common.all_cinemas') }}</flux:select.option>
                    @foreach ($cinemas as $cinema)
                        <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input type="date" wire:model.live="filterDate" :placeholder="__('admin.screening.fields.filter_date')" :aria-label="__('admin.screening.fields.filter_date')" />

            @if ($filterTheaters->isNotEmpty())
                <flux:select wire:model.live="filterTheaterId" class="w-40">
                    <flux:select.option value="">{{ __('admin.common.all_theaters') }}</flux:select.option>
                    @foreach ($filterTheaters as $theater)
                        <flux:select.option value="{{ $theater->id }}">{{ $theater->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($canEditAny)
                <flux:button variant="primary" wire:click="createScreening">{{ __('admin.screening.actions.create') }}</flux:button>
            @endif
        </div>
    </div>

    <flux:table>
        <flux:table.columns>
            @if ($canSelectCinema)
                <flux:table.column>{{ __('admin.screening.fields.cinema') }}</flux:table.column>
            @endif
            <flux:table.column>{{ __('admin.screening.fields.theater') }}</flux:table.column>
            <flux:table.column>{{ __('admin.screening.fields.starts_at') }}</flux:table.column>
            <flux:table.column>{{ __('admin.screening.fields.ends_at') }}</flux:table.column>
            <flux:table.column>{{ __('admin.screening.fields.movie') }}</flux:table.column>
            <flux:table.column>{{ __('admin.screening.fields.format') }}</flux:table.column>
            <flux:table.column>{{ __('admin.screening.fields.reservations_count') }}</flux:table.column>
            <flux:table.column>{{ __('admin.screening.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($screenings as $screening)
                @php
                    $canUpdate = $screening->reservations_count === 0 && \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $screening);
                    // 削除は開始前の回に限る（6.2「上映回は上映期間終了後も保持」、4.8.6追記表）。
                    $canDelete =$screening->reservations_count === 0 && $screening->starts_at->isFuture() && \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('delete', $screening);
                @endphp
                <flux:table.row :key="$screening->id">
                    @if ($canSelectCinema)
                        <flux:table.cell>{{ $screening->booking->cinema->name }}</flux:table.cell>
                    @endif
                    <flux:table.cell>{{ $screening->theater->name }}</flux:table.cell>
                    <flux:table.cell>{{ $screening->starts_at->format('H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $screening->ends_at->format('H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $screening->booking->movie->title }}</flux:table.cell>
                    <flux:table.cell>{{ $screening->booking->format->name }}</flux:table.cell>
                    <flux:table.cell>{{ $screening->reservations_count }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate || $canDelete)
                            <div class="flex gap-2">
                                @if ($canUpdate)
                                    <flux:button size="sm" wire:click="editScreening({{ $screening->id }})">{{ __('admin.screening.actions.edit') }}</flux:button>
                                @endif
                                @if ($canDelete)
                                    <flux:button size="sm" variant="danger" wire:click="deleteScreening({{ $screening->id }})" wire:confirm="{{ __('admin.screening.actions.delete_confirm') }}">{{ __('admin.screening.actions.delete') }}</flux:button>
                                @endif
                            </div>
                        @elseif ($screening->reservations_count > 0)
                            <flux:text size="sm" variant="subtle">{{ __('admin.screening.notices.has_reservations') }}</flux:text>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="{{ $canSelectCinema ? 8 : 7 }}">{{ __('admin.screening.notices.empty') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:pagination :paginator="$screenings" />

    @if ($canEditAny)
        <flux:modal wire:model.self="showForm" class="md:w-[32rem]">
            <form wire:submit="save" class="flex flex-col gap-6">
                <flux:heading level="2">
                    @if ($screening_id === null)
                        {{ __('admin.screening.actions.create') }}
                    @else
                        {{ __('admin.screening.actions.edit') }}
                    @endif
                </flux:heading>

                <flux:input type="datetime-local" wire:model.live="starts_at" :label="__('admin.screening.fields.starts_at')" required />
                <flux:error name="starts_at" />

                @if ($selectableBookings->isEmpty())
                    <flux:callout variant="warning" :text="__('admin.screening.notices.no_booking')" />
                @else
                    <flux:select wire:model.live="booking_id" :label="__('admin.screening.fields.booking')">
                        <flux:select.option value="">{{ __('admin.screening.fields.booking') }}</flux:select.option>
                        @foreach ($selectableBookings as $booking)
                            @php
                                $bookingLabel = ($canSelectCinema ? $booking->cinema->name.'／' : '')
                                    .$booking->movie->title.'／'.$booking->format->name.'／'
                                    .$booking->starts_on->format('Y-m-d').'〜'.$booking->ends_on->format('Y-m-d');
                            @endphp
                            <flux:select.option value="{{ $booking->id }}">{{ $bookingLabel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:error name="booking_id" />

                @if ($booking_id === '')
                    <flux:text size="sm">{{ __('admin.screening.notices.select_booking') }}</flux:text>
                @endif

                <flux:select wire:model="theater_id" :label="__('admin.screening.fields.theater')">
                    <flux:select.option value="">{{ __('admin.screening.fields.theater') }}</flux:select.option>
                    @foreach ($selectableTheaters as $theater)
                        <flux:select.option value="{{ $theater->id }}">{{ $theater->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="theater_id" />

                <div>
                    <flux:input type="datetime-local" wire:model="ends_at" :label="__('admin.screening.fields.ends_at')" required />
                    <flux:text size="sm">{{ __('admin.screening.notices.ends_at_auto') }}</flux:text>
                </div>
                <flux:error name="ends_at" />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancel">{{ __('admin.screening.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.screening.actions.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
