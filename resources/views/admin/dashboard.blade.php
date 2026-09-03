<x-layouts::admin :title="__('admin.dashboard.title')">
    <div class="flex flex-col gap-4">
        <flux:heading level="1">{{ __('admin.dashboard.title') }}</flux:heading>

        <flux:text>
            {{ __('admin.dashboard.greeting', ['name' => auth('admin')->user()->name]) }}
        </flux:text>

        <flux:callout>
            {{ __('admin.dashboard.pending_notice') }}
        </flux:callout>
    </div>
</x-layouts::admin>
