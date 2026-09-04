@php
    $currentAdmin = auth('admin')->user();
    $canSelectCinema = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('viewAllCinemas', \App\Models\Cinema::class);
    $canEditAny = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('updateAny', \App\Models\Booking::class);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('admin.booking.title') }}</flux:heading>

        <div class="flex items-center gap-2">
            @if ($canSelectCinema)
                <flux:select wire:model.live="selectedCinemaId" class="w-56">
                    <flux:select.option value="">{{ __('admin.common.all_cinemas') }}</flux:select.option>
                    @foreach ($cinemas as $cinema)
                        <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($canEditAny)
                <flux:button variant="primary" wire:click="createBooking">{{ __('admin.booking.actions.create') }}</flux:button>
            @endif
        </div>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('admin.booking.fields.cinema') }}</flux:table.column>
            <flux:table.column>{{ __('admin.booking.fields.movie') }}</flux:table.column>
            <flux:table.column>{{ __('admin.booking.fields.format') }}</flux:table.column>
            <flux:table.column>{{ __('admin.booking.fields.starts_on') }}</flux:table.column>
            <flux:table.column>{{ __('admin.booking.fields.ends_on') }}</flux:table.column>
            <flux:table.column>{{ __('admin.booking.fields.surcharge') }}</flux:table.column>
            <flux:table.column>{{ __('admin.booking.fields.screenings_count') }}</flux:table.column>
            <flux:table.column>{{ __('admin.booking.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($bookings as $booking)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $booking);
                @endphp
                <flux:table.row :key="$booking->id">
                    <flux:table.cell>{{ $booking->cinema->name }}</flux:table.cell>
                    <flux:table.cell>{{ $booking->movie->title }}</flux:table.cell>
                    <flux:table.cell>{{ $booking->format->name }}</flux:table.cell>
                    <flux:table.cell>{{ $booking->starts_on->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>{{ $booking->ends_on->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>{{ __('admin.common.yen', ['amount' => number_format($booking->surcharge)]) }}</flux:table.cell>
                    <flux:table.cell>{{ $booking->screenings_count }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate)
                            <flux:button size="sm" wire:click="editBooking({{ $booking->id }})">{{ __('admin.booking.actions.edit') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:pagination :paginator="$bookings" />

    @if ($canEditAny)
        <flux:modal wire:model.self="showForm" class="md:w-[32rem]">
            <form wire:submit="save" class="flex flex-col gap-6">
                <flux:heading level="2">
                    @if ($booking_id === null)
                        {{ __('admin.booking.actions.create') }}
                    @else
                        {{ __('admin.booking.actions.edit') }}
                    @endif
                </flux:heading>

                @if ($hasScreenings)
                    <flux:callout variant="warning" :text="__('admin.booking.notices.locked_by_screenings')" />
                @endif

                <flux:select wire:model.live="cinema_id" :label="__('admin.booking.fields.cinema')" :disabled="$hasScreenings">
                    <flux:select.option value="">{{ __('admin.booking.fields.cinema') }}</flux:select.option>
                    @foreach ($cinemas as $cinema)
                        <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="cinema_id" />

                <flux:select wire:model.live="movie_id" :label="__('admin.booking.fields.movie')" :disabled="$hasScreenings">
                    <flux:select.option value="">{{ __('admin.booking.fields.movie') }}</flux:select.option>
                    @foreach ($movies as $movie)
                        <flux:select.option value="{{ $movie->id }}">{{ $movie->title }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="movie_id" />

                @if ($selectableFormats->isEmpty())
                    {{-- 館と作品の双方が選ばれたうえで空なら、6.6 の積集合が無い状態（4.8.6追記表）。 --}}
                    @if ($cinema_id !== '' && $movie_id !== '')
                        <flux:text>{{ __('admin.booking.notices.no_common_format') }}</flux:text>
                    @else
                        <flux:text>{{ __('admin.booking.notices.select_cinema_and_movie') }}</flux:text>
                    @endif
                @endif

                <flux:select wire:model.live="format_id" :label="__('admin.booking.fields.format')" :disabled="$hasScreenings">
                    <flux:select.option value="">{{ __('admin.booking.fields.format') }}</flux:select.option>
                    @foreach ($selectableFormats as $format)
                        <flux:select.option value="{{ $format->id }}">{{ $format->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="format_id" />

                <flux:input wire:model="starts_on" :label="__('admin.booking.fields.starts_on')" type="date" required />
                <flux:error name="starts_on" />

                <flux:input wire:model="ends_on" :label="__('admin.booking.fields.ends_on')" type="date" required />
                <flux:error name="ends_on" />

                <flux:input wire:model="surcharge" :label="__('admin.booking.fields.surcharge')" type="number" min="0" required />
                <flux:error name="surcharge" />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancel">{{ __('admin.booking.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.booking.actions.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
