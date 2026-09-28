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
        {{-- キャンセル済みの予約は座席をキャンセル時点のまま残すため、上映回の編集後は
             食い違って見えうる（4.3.17 / 旧12章 残課題40）。 --}}
        @if ($reservations->contains(fn ($reservation) => $reservation->status === \App\Enums\ReservationStatus::Cancelled))
            <flux:callout variant="warning" :text="__('admin.common.cancelled_reservation_note')" />
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('admin.reservation_search.fields.reservation_no') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.name') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.cinema') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.movie') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.starts_at') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.seat') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.fields.entry_state') }}</flux:table.column>
                <flux:table.column>{{ __('admin.reservation_search.actions.label') }}</flux:table.column>
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
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-2">
                                @if ($reservation->status === \App\Enums\ReservationStatus::Paid)
                                    <flux:button size="sm" wire:click="showQrCode({{ $reservation->id }})">
                                        {{ __('admin.reservation_search.actions.qr') }}
                                    </flux:button>
                                @endif

                                @if ($reservation->isCheckedIn() && ! $reservation->screening->hasEnded() && $canRevokeCheckIn)
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        wire:click="revokeCheckIn({{ $reservation->id }})"
                                        wire:confirm="{{ __('admin.reservation_search.revoke.confirm') }}"
                                    >
                                        {{ __('admin.reservation_search.actions.revoke') }}
                                    </flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- 入場用QRコード（4.8.5 予約検索の要件2 / 4.6.6）。A-10 の明細モーダルと同じ作法。 --}}
    <flux:modal wire:model.self="showQr" class="md:w-[24rem]">
        <div class="flex flex-col gap-4">
            <flux:heading level="2">{{ __('admin.reservation_search.qr_modal.heading') }}</flux:heading>

            @if ($qrReservation)
                <p class="text-sm text-zinc-600">
                    {{ $qrReservation->formattedReservationNo() }} ／ {{ $qrReservation->displayName() }}
                </p>

                <div class="flex justify-center">
                    <x-entry-qr-code :code="$qrReservation->entry_code" :alt="__('admin.reservation_search.qr_modal.heading')" />
                </div>

                <p class="text-sm">{{ __('admin.reservation_search.qr_modal.note') }}</p>
            @endif

            <div class="flex justify-end">
                <flux:button wire:click="closeQrCode">{{ __('admin.reservation_search.actions.close') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
