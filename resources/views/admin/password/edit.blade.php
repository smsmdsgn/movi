<div class="flex flex-col gap-4">
    <flux:heading level="1">{{ __('admin.password.title') }}</flux:heading>

    <flux:text>{{ __('admin.password.notice') }}</flux:text>

    <form wire:submit="save" class="flex max-w-md flex-col gap-6">
        <flux:input
            wire:model="current_password"
            type="password"
            autocomplete="current-password"
            :label="__('admin.password.fields.current_password')"
            required
        />

        <flux:input
            wire:model="password"
            type="password"
            autocomplete="new-password"
            :label="__('admin.password.fields.password')"
            :description="__('admin.password.hints.password')"
            required
        />

        <flux:input
            wire:model="password_confirmation"
            type="password"
            autocomplete="new-password"
            :label="__('admin.password.fields.password_confirmation')"
            required
        />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('admin.password.actions.save') }}</flux:button>
        </div>
    </form>
</div>
