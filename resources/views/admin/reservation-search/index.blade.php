<div class="flex flex-col gap-4">
    <flux:heading level="1">{{ __('admin.reservation_search.title') }}</flux:heading>

    <flux:text>{{ __('admin.reservation_search.notice') }}</flux:text>

    <form wire:submit="search" class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="searchBy" :label="__('admin.reservation_search.fields.search_by')" class="w-44">
            <flux:select.option value="reservation_no">{{ __('admin.reservation_search.by.reservation_no') }}</flux:select.option>
            <flux:select.option value="kana">{{ __('admin.reservation_search.by.kana') }}</flux:select.option>
            <flux:select.option value="phone">{{ __('admin.reservation_search.by.phone') }}</flux:select.option>
        </flux:select>

        <flux:input
            wire:model="term"
            :label="__('admin.reservation_search.fields.term')"
            :description="__('admin.reservation_search.hints.'.$searchBy)"
            class="w-72"
            required
        />

        <flux:button type="submit" variant="primary">{{ __('admin.reservation_search.actions.search') }}</flux:button>
        <flux:button type="button" wire:click="clear">{{ __('admin.reservation_search.actions.clear') }}</flux:button>
    </form>

    @if (! $searched)
        <flux:text>{{ __('admin.reservation_search.notices.before_search') }}</flux:text>
    @elseif ($tooMany)
        <flux:callout variant="warning">
            {{ __('admin.reservation_search.notices.too_many', ['limit' => $resultLimit]) }}
        </flux:callout>
    @elseif ($reservations->isEmpty())
        <flux:text>{{ __('admin.reservation_search.notices.empty') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('admin.reservation_search.fields.reservation_no') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.name') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.cinema') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.movie') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.starts_at') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.seat') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.entry_state') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($reservations as $reservation)
                    <flux:table.row :key="$reservation->id">
                        <flux:table.cell>{{ $reservation->formattedReservationNo() }}</flux:table.cell>
                        <flux:table.cell>{{ $reservation->displayName() }}</flux:table.cell>
                        <flux:table.cell>{{ $reservation->screening->booking->cinema->name }}</flux:table.cell>
                        <flux:table.cell>{{ $reservation->screening->booking->movie->title }}</flux:table.cell>
                        <flux:table.cell>{{ $reservation->screening->starts_at->format('Y/m/d H:i') }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $reservation->seats->map(fn ($seat) => $seat->seat->displayName())->implode('、') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($reservation->status === \App\Enums\ReservationStatus::Cancelled)
                                <flux:badge color="zinc">{{ __('admin.reservation_search.state.cancelled') }}</flux:badge>
                            @elseif ($reservation->isCheckedIn())
                                <flux:badge color="green">{{ __('admin.reservation_search.state.checked_in') }}</flux:badge>
                            @else
                                <flux:badge color="amber">{{ __('admin.reservation_search.state.not_checked_in') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:text>{{ __('admin.reservation_search.notices.qr_pending') }}</flux:text>
    @endif
</div>
