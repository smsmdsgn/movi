@php
    $currentAdmin = auth('admin')->user();
    $canEditAny = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('updateAny', \App\Models\TicketType::class);
@endphp
<div class="flex flex-col gap-4">
    <flux:heading level="1">{{ __('admin.ticket_type.title') }}</flux:heading>

    <flux:text>{{ __('admin.ticket_type.price_notice') }}</flux:text>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('admin.ticket_type.fields.name') }}</flux:table.column>
            <flux:table.column>{{ __('admin.ticket_type.fields.price') }}</flux:table.column>
            <flux:table.column>{{ __('admin.ticket_type.fields.condition') }}</flux:table.column>
            <flux:table.column>{{ __('admin.ticket_type.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($ticketTypes as $ticketType)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $ticketType);
                @endphp
                <flux:table.row :key="$ticketType->id">
                    <flux:table.cell>{{ $ticketType->name }}</flux:table.cell>
                    <flux:table.cell>{{ __('admin.common.yen', ['amount' => number_format($ticketType->price)]) }}</flux:table.cell>
                    <flux:table.cell>{{ $ticketType->condition }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate)
                            <flux:button size="sm" wire:click="editTicketType({{ $ticketType->id }})">{{ __('admin.ticket_type.actions.edit') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($canEditAny)
        <flux:modal wire:model.self="showEditForm" class="md:w-96">
            <form wire:submit="saveTicketType" class="flex flex-col gap-6">
                <flux:heading level="2">{{ __('admin.ticket_type.actions.edit') }}</flux:heading>

                {{-- 券種名は編集対象外（6.5.1 の固定集合、4.8.6追記表）のため表示のみ。 --}}
                <flux:input :value="$name" :label="__('admin.ticket_type.fields.name')" disabled />
                <flux:input wire:model="price" :label="__('admin.ticket_type.fields.price')" type="number" min="1" required />
                <flux:input wire:model="condition" :label="__('admin.ticket_type.fields.condition')" />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancelEditTicketType">{{ __('admin.ticket_type.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.ticket_type.actions.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
