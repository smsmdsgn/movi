<div class="flex flex-col gap-6">
    <flux:heading level="1">{{ __('admin.auth.heading') }}</flux:heading>

    <form method="POST" wire:submit="login" class="flex flex-col gap-6">
        <flux:input
            wire:model="login_id"
            :label="__('admin.auth.login_id')"
            type="text"
            required
            autofocus
            autocomplete="username"
        />

        <flux:input
            wire:model="password"
            :label="__('admin.auth.password')"
            type="password"
            required
            autocomplete="current-password"
            viewable
        />

        <flux:button variant="primary" type="submit" class="w-full">
            {{ __('admin.auth.submit') }}
        </flux:button>
    </form>
</div>
