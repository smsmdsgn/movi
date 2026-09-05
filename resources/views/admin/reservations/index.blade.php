<div class="flex flex-col gap-4">
    <flux:heading level="1">{{ __('admin.reservation.title') }}</flux:heading>

    <flux:text>{{ __('admin.reservation.notice') }}</flux:text>

    <div class="flex flex-wrap items-end gap-3">
        @if ($canSelectCinema)
            <flux:select wire:model.live="selectedCinemaId" :label="__('admin.reservation.fields.cinema')" class="w-56">
                <flux:select.option value="">{{ __('admin.common.all_cinemas') }}</flux:select.option>
                @foreach ($cinemas as $cinema)
                    <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:input wire:model.live="filterDate" type="date" :label="__('admin.reservation.fields.filter_date')" class="w-44" />

        @if ($theaters->isNotEmpty())
            <flux:select wire:model.live="filterTheaterId" :label="__('admin.reservation.fields.theater')" class="w-44">
                <flux:select.option value="">{{ __('admin.common.all_theaters') }}</flux:select.option>
                @foreach ($theaters as $theater)
                    <flux:select.option value="{{ $theater->id }}">{{ $theater->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($screenings->isEmpty())
        <flux:text>{{ __('admin.reservation.notices.empty') }}</flux:text>
    @else
        <flux:table :paginate="$screenings">
            <flux:table.columns>
                <flux:table.column>{{ __('admin.reservation.fields.starts_at') }}</flux:table.column>
                @if ($canSelectCinema)
                    <flux:table.column>{{ __('admin.reservation.fields.cinema') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('admin.reservation.fields.theater') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation.fields.movie') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation.fields.seats_ratio') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation.fields.reservations_count') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation.fields.checked_in') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation.actions.label') }}</flux:table.column>
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
                            {{ __('admin.reservation.seats_ratio', [
                                'booked' => $screening->booked_seats_count,
                                'total' => $screening->theater->available_seats_count,
                            ]) }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $screening->paid_reservations_count }}</flux:table.cell>
                        <flux:table.cell>
                            {{ __('admin.reservation.checked_in_ratio', [
                                'done' => $screening->checked_in_count,
                                'total' => $screening->paid_reservations_count,
                            ]) }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" wire:click="showReservations({{ $screening->id }})">
                                {{ __('admin.reservation.actions.detail') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal wire:model.self="showDetail" class="md:w-[48rem]">
        <div class="flex flex-col gap-4">
            <flux:heading level="2">{{ __('admin.reservation.actions.detail') }}</flux:heading>

            @if ($reservations->isEmpty())
                <flux:text>{{ __('admin.reservation.notices.no_reservations') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('admin.reservation.fields.reservation_no') }}</flux:table.column>
                        <flux:table.column>{{ __('admin.reservation.fields.name') }}</flux:table.column>
                        <flux:table.column>{{ __('admin.reservation.fields.seat') }}</flux:table.column>
                        <flux:table.column>{{ __('admin.reservation.fields.ticket_type') }}</flux:table.column>
                        <flux:table.column>{{ __('admin.reservation.fields.entry_state') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($reservations as $reservation)
                            <flux:table.row :key="$reservation->id">
                                <flux:table.cell>{{ $reservation->reservation_no }}</flux:table.cell>
                                <flux:table.cell>{{ $reservation->displayName() }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $reservation->seats->map(fn ($seat) => $seat->seat->displayName())->implode('、') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $reservation->seats->map(fn ($seat) => $seat->ticketType->name)->implode('、') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($reservation->status === \App\Enums\ReservationStatus::Cancelled)
                                        <flux:badge color="zinc">{{ __('admin.reservation.state.cancelled') }}</flux:badge>
                                    @elseif ($reservation->isCheckedIn())
                                        <flux:badge color="green">{{ __('admin.reservation.state.checked_in') }}</flux:badge>
                                    @else
                                        <flux:badge color="amber">{{ __('admin.reservation.state.not_checked_in') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif

            <div class="flex justify-end">
                <flux:button wire:click="closeDetail">{{ __('admin.reservation.actions.close') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
