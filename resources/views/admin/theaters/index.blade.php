@php
    $currentAdmin = auth('admin')->user();
    $canSelectCinema = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('viewAllCinemas', \App\Models\Cinema::class);
    $canEditAny = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('updateAny', \App\Models\Theater::class);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('admin.theater.title') }}</flux:heading>

        @if ($canSelectCinema)
            <flux:select wire:model.live="selectedCinemaId" class="w-56">
                <flux:select.option value="">{{ __('admin.common.all_cinemas') }}</flux:select.option>
                @foreach ($cinemas as $cinema)
                    <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    <flux:table>
        <flux:table.columns>
            @if ($canSelectCinema)
                <flux:table.column>{{ __('admin.theater.fields.cinema') }}</flux:table.column>
            @endif
            <flux:table.column>{{ __('admin.theater.fields.number') }}</flux:table.column>
            <flux:table.column>{{ __('admin.theater.fields.name') }}</flux:table.column>
            <flux:table.column>{{ __('admin.theater.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($theaters as $theater)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $theater);
                @endphp
                <flux:table.row :key="$theater->id">
                    @if ($canSelectCinema)
                        <flux:table.cell>{{ $theater->cinema->name }}</flux:table.cell>
                    @endif
                    <flux:table.cell>{{ $theater->number }}</flux:table.cell>
                    <flux:table.cell>{{ $theater->name }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-2">
                            @if ($canUpdate)
                                <flux:button size="sm" wire:click="editTheater({{ $theater->id }})">{{ __('admin.theater.actions.edit') }}</flux:button>
                            @endif
                            <flux:button size="sm" wire:click="manageSeats({{ $theater->id }})">{{ __('admin.theater.actions.manage_seats') }}</flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($canEditAny)
        <flux:modal wire:model.self="showEditForm" class="md:w-96">
            <form wire:submit="saveTheater" class="flex flex-col gap-6">
                <flux:heading level="2">{{ __('admin.theater.actions.edit') }}</flux:heading>

                <flux:input wire:model="number" :label="__('admin.theater.fields.number')" type="number" min="1" required />
                <flux:input wire:model="name" :label="__('admin.theater.fields.name')" required />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancelEditTheater">{{ __('admin.theater.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.theater.actions.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <flux:modal wire:model.self="showSeats" class="md:w-[48rem]">
        <div class="flex flex-col gap-4">
            <flux:heading level="2">{{ __('admin.theater.actions.manage_seats') }}（{{ $seatTheaterName }}）</flux:heading>

            <div class="flex flex-wrap items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                <span class="flex items-center gap-1"><flux:button size="xs" variant="filled" class="pointer-events-none" tabindex="-1" aria-hidden="true">A01</flux:button> {{ __('admin.theater.seat_legend.available') }}</span>
                <span class="flex items-center gap-1"><flux:button size="xs" variant="filled" class="pointer-events-none opacity-50 line-through" tabindex="-1" aria-hidden="true">A01</flux:button> {{ __('admin.theater.seat_legend.unavailable') }}</span>
                <span>♿ {{ __('admin.theater.seat_legend.wheelchair') }}</span>
                <span class="flex items-center gap-1"><flux:button size="xs" variant="primary" class="pointer-events-none" tabindex="-1" aria-hidden="true">A01</flux:button> {{ __('admin.theater.seat_legend.executive') }}</span>
            </div>

            <div class="max-h-[70vh] overflow-auto rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="grid gap-1" style="grid-auto-rows: 2.75rem; grid-auto-columns: 3.25rem;">
                    @foreach ($seats as $seat)
                        @php
                            $seatStateLabel = $seat->is_available
                                ? __('admin.theater.seat_legend.available')
                                : __('admin.theater.seat_legend.unavailable');
                        @endphp
                        <flux:button
                            size="xs"
                            wire:click="toggleSeat({{ $seat->id }})"
                            wire:key="seat-{{ $seat->id }}"
                            variant="{{ $seat->seatType->display_class === \App\Enums\SeatDisplayClass::Executive ? 'primary' : 'filled' }}"
                            class="min-w-0 {{ $seat->is_available ? '' : 'opacity-50 line-through' }}"
                            style="grid-row: {{ $seat->grid_row }}; grid-column: {{ $seat->grid_col }};"
                            aria-label="{{ $seat->displayName() }} {{ $seatStateLabel }}"
                        >
                            {{ $seat->displayName() }}{{ $seat->seatType->display_class === \App\Enums\SeatDisplayClass::Wheelchair ? '♿' : '' }}
                        </flux:button>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end">
                <flux:button wire:click="closeSeats">{{ __('admin.theater.actions.close') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
