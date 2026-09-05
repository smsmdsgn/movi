@php
    $currentAdmin = auth('admin')->user();
    $canCreate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('create', \App\Models\Admin::class);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('admin.account.title') }}</flux:heading>

        @if ($canCreate)
            <flux:button variant="primary" wire:click="create">{{ __('admin.account.actions.create') }}</flux:button>
        @endif
    </div>

    <flux:text>{{ __('admin.account.notice') }}</flux:text>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('admin.account.fields.login_id') }}</flux:table.column>
            <flux:table.column>{{ __('admin.account.fields.name') }}</flux:table.column>
            <flux:table.column>{{ __('admin.account.fields.role') }}</flux:table.column>
            <flux:table.column>{{ __('admin.account.fields.cinema_id') }}</flux:table.column>
            <flux:table.column>{{ __('admin.account.fields.is_active') }}</flux:table.column>
            <flux:table.column>{{ __('admin.account.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($accounts as $account)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $account);
                    $canManageAccess = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('manageAccess', $account);
                @endphp
                <flux:table.row :key="$account->id">
                    <flux:table.cell>{{ $account->login_id }}</flux:table.cell>
                    <flux:table.cell>{{ $account->name }}</flux:table.cell>
                    <flux:table.cell>{{ __('admin.role.'.$account->role->value) }}</flux:table.cell>
                    <flux:table.cell>{{ $account->cinema?->name ?? __('admin.common.all_cinemas') }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($account->is_active)
                            <flux:badge color="green">{{ __('admin.account.state.active') }}</flux:badge>
                        @else
                            <flux:badge color="zinc">{{ __('admin.account.state.inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-2">
                            @if ($canUpdate)
                                <flux:button size="sm" wire:click="edit({{ $account->id }})">{{ __('admin.account.actions.edit') }}</flux:button>
                            @endif

                            @if ($canManageAccess)
                                <flux:button size="sm" wire:click="toggleActive({{ $account->id }})">
                                    {{ $account->is_active ? __('admin.account.actions.deactivate') : __('admin.account.actions.activate') }}
                                </flux:button>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($canCreate)
        <flux:modal wire:model.self="showForm" class="md:w-[32rem]">
            <form wire:submit="save" class="flex flex-col gap-6">
                <flux:heading level="2">
                    {{ $account_id === null ? __('admin.account.actions.create') : __('admin.account.actions.edit') }}
                </flux:heading>

                <flux:input wire:model="login_id" :label="__('admin.account.fields.login_id')" :description="__('admin.account.hints.login_id')" required />
                <flux:input wire:model="name" :label="__('admin.account.fields.name')" required />

                <flux:select wire:model.live="role" :label="__('admin.account.fields.role')" required>
                    @foreach ($roles as $roleOption)
                        <flux:select.option value="{{ $roleOption->value }}">{{ __('admin.role.'.$roleOption->value) }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($role !== \App\Enums\AdminRole::SuperAdmin->value)
                    <flux:select wire:model="cinema_id" :label="__('admin.account.fields.cinema_id')" required>
                        <flux:select.option value="">{{ __('admin.account.fields.cinema_id') }}</flux:select.option>
                        @foreach ($cinemas as $cinema)
                            <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input
                    wire:model="password"
                    type="password"
                    :label="__('admin.account.fields.password')"
                    :description="$account_id === null ? __('admin.account.hints.password') : __('admin.account.hints.password_optional')"
                    :required="$account_id === null"
                />
                <flux:input
                    wire:model="password_confirmation"
                    type="password"
                    :label="__('admin.account.fields.password_confirmation')"
                    :required="$account_id === null"
                />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancel">{{ __('admin.account.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.account.actions.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
