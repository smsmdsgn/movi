@php
    $currentAdmin = auth('admin')->user();
    $canEditAny = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('updateAny', \App\Models\Format::class);
@endphp
<div class="flex flex-col gap-4">
    <flux:heading level="1">{{ __('admin.format.title') }}</flux:heading>

    <flux:text>{{ __('admin.format.surcharge_notice') }}</flux:text>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('admin.format.fields.name') }}</flux:table.column>
            <flux:table.column>{{ __('admin.format.fields.default_surcharge') }}</flux:table.column>
            <flux:table.column>{{ __('admin.format.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($formats as $format)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $format);
                @endphp
                <flux:table.row :key="$format->id">
                    <flux:table.cell>{{ $format->name }}</flux:table.cell>
                    <flux:table.cell>{{ __('admin.common.yen', ['amount' => number_format($format->default_surcharge)]) }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate)
                            <flux:button size="sm" wire:click="editFormat({{ $format->id }})">{{ __('admin.format.actions.edit') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($canEditAny)
        <flux:modal wire:model.self="showEditForm" class="md:w-96">
            <form wire:submit="saveFormat" class="flex flex-col gap-6">
                <flux:heading level="2">{{ __('admin.format.actions.edit') }}</flux:heading>

                {{-- 規格名は編集対象外（6.6 の固定集合、4.8.6追記表）のため表示のみ。 --}}
                <flux:input :value="$name" :label="__('admin.format.fields.name')" disabled />
                <flux:input wire:model="default_surcharge" :label="__('admin.format.fields.default_surcharge')" type="number" min="0" required />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancelEditFormat">{{ __('admin.format.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.format.actions.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
