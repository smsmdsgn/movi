@php
    $currentAdmin = auth('admin')->user();
    $canCreate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('create', \App\Models\Cinema::class);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('admin.cinema.title') }}</flux:heading>

        @if ($canCreate)
            <flux:button variant="primary" wire:click="create">{{ __('admin.cinema.actions.create') }}</flux:button>
        @endif
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('admin.cinema.fields.name') }}</flux:table.column>
            <flux:table.column>{{ __('admin.cinema.fields.slug') }}</flux:table.column>
            <flux:table.column>{{ __('admin.cinema.fields.address') }}</flux:table.column>
            <flux:table.column>{{ __('admin.cinema.fields.business_hours') }}</flux:table.column>
            <flux:table.column>{{ __('admin.cinema.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($cinemas as $cinema)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $cinema);
                @endphp
                <flux:table.row :key="$cinema->id">
                    <flux:table.cell>{{ $cinema->name }}</flux:table.cell>
                    <flux:table.cell>{{ $cinema->slug }}</flux:table.cell>
                    <flux:table.cell>{{ $cinema->address }}</flux:table.cell>
                    <flux:table.cell>{{ $cinema->business_hours }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate)
                            <flux:button size="sm" wire:click="edit({{ $cinema->id }})">{{ __('admin.cinema.actions.edit') }}</flux:button>
                        @else
                            <flux:button size="sm" wire:click="view({{ $cinema->id }})">{{ __('admin.cinema.actions.view') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal wire:model.self="showForm" class="md:w-[36rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading level="2">
                @if ($readOnly)
                    {{ __('admin.cinema.actions.view') }}
                @elseif ($cinema_id === null)
                    {{ __('admin.cinema.actions.create') }}
                @else
                    {{ __('admin.cinema.actions.edit') }}
                @endif
            </flux:heading>

            <flux:input wire:model="slug" :label="__('admin.cinema.fields.slug')" :disabled="$readOnly" required />
            <flux:input wire:model="name" :label="__('admin.cinema.fields.name')" :disabled="$readOnly" required />
            <flux:input wire:model="concept" :label="__('admin.cinema.fields.concept')" :disabled="$readOnly" required />
            <flux:input wire:model="address" :label="__('admin.cinema.fields.address')" :disabled="$readOnly" required />
            <flux:input wire:model="phone" :label="__('admin.cinema.fields.phone')" :disabled="$readOnly" required />
            <flux:input wire:model="business_hours" :label="__('admin.cinema.fields.business_hours')" :disabled="$readOnly" required />
            <flux:textarea wire:model="facility_info" :label="__('admin.cinema.fields.facility_info')" :disabled="$readOnly" required>{{ $facility_info }}</flux:textarea>
            <flux:textarea wire:model="access_note" :label="__('admin.cinema.fields.access_note')" :disabled="$readOnly" required>{{ $access_note }}</flux:textarea>
            <flux:input wire:model="map_embed_url" :label="__('admin.cinema.fields.map_embed_url')" type="url" :disabled="$readOnly" required />

            <div class="flex justify-end gap-2">
                @if ($readOnly)
                    <flux:button type="button" wire:click="cancel">{{ __('admin.cinema.actions.close') }}</flux:button>
                @else
                    <flux:button type="button" wire:click="cancel">{{ __('admin.cinema.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.cinema.actions.save') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
</div>
