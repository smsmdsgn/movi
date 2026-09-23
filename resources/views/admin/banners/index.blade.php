@php
    $currentAdmin = auth('admin')->user();
    $canEditAny = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('updateAny', \App\Models\Banner::class);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('admin.banner.title') }}</flux:heading>

        <div class="flex items-center gap-2">
            <flux:select wire:model.live="filterPosition" class="w-40">
                <flux:select.option value="">{{ __('admin.banner.filters.all_positions') }}</flux:select.option>
                @foreach (\App\Enums\BannerPosition::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ __('admin.banner.positions.'.$case->value) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="selectedCinemaId" class="w-56">
                <flux:select.option value="">{{ __('admin.common.all_cinemas') }}</flux:select.option>
                @foreach ($cinemas as $cinema)
                    <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($canEditAny)
                <flux:button variant="primary" wire:click="createBanner">{{ __('admin.banner.actions.create') }}</flux:button>
            @endif
        </div>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('admin.banner.fields.image') }}</flux:table.column>
            <flux:table.column>{{ __('admin.banner.fields.position') }}</flux:table.column>
            <flux:table.column>{{ __('admin.banner.fields.cinema') }}</flux:table.column>
            <flux:table.column>{{ __('admin.banner.fields.alt') }}</flux:table.column>
            <flux:table.column>{{ __('admin.banner.fields.link_url') }}</flux:table.column>
            <flux:table.column>{{ __('admin.banner.fields.sort_order') }}</flux:table.column>
            <flux:table.column>{{ __('admin.banner.fields.period') }}</flux:table.column>
            <flux:table.column>{{ __('admin.banner.fields.state') }}</flux:table.column>
            <flux:table.column>{{ __('admin.banner.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($banners as $banner)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $banner);
                    $isLive = $banner->isVisibleAt(now());
                    $isScheduled = ! $isLive && $banner->starts_at?->isFuture();
                    $stateLabel = match (true) {
                        $isLive => __('admin.banner.states.live'),
                        $isScheduled => __('admin.banner.states.scheduled'),
                        default => __('admin.banner.states.ended'),
                    };
                    $imageUrl = $banner->imageUrl();
                @endphp
                <flux:table.row :key="$banner->id">
                    <flux:table.cell>
                        @if ($imageUrl)
                            <img src="{{ $imageUrl }}" alt="{{ $banner->alt }}" class="h-12 w-auto" />
                        @else
                            <x-banner-placeholder :position="$banner->position" class="w-32" />
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ __('admin.banner.positions.'.$banner->position->value) }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $banner->cinema_id === null ? __('admin.banner.all_cinemas') : $banner->cinema->name }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $banner->alt }}</flux:table.cell>
                    <flux:table.cell>
                        {{-- リンクとして踏ませない。管理者が未検証の外部URLを誤って開かないようにする。 --}}
                        {{ $banner->link_url ?? __('admin.banner.notices.no_link') }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $banner->sort_order }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($banner->starts_at === null && $banner->ends_at === null)
                            {{ __('admin.banner.notices.no_period') }}
                        @else
                            {{ $banner->starts_at?->format('Y-m-d H:i') ?? '—' }} 〜 {{ $banner->ends_at?->format('Y-m-d H:i') ?? '—' }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $stateLabel }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate)
                            <flux:button size="sm" wire:click="editBanner({{ $banner->id }})">{{ __('admin.banner.actions.edit') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="9">{{ __('admin.banner.notices.empty') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:pagination :paginator="$banners" />

    @if ($canEditAny)
        <flux:modal wire:model.self="showForm" class="md:w-[36rem]">
            <form wire:submit="save" class="flex flex-col gap-6">
                <flux:heading level="2">
                    @if ($banner_id === null)
                        {{ __('admin.banner.actions.create') }}
                    @else
                        {{ __('admin.banner.actions.edit') }}
                    @endif
                </flux:heading>

                <div>
                    <flux:select wire:model.live="position" :label="__('admin.banner.fields.position')">
                        <flux:select.option value="">{{ __('admin.banner.notices.select_position') }}</flux:select.option>
                        @foreach (\App\Enums\BannerPosition::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ __('admin.banner.positions.'.$case->value) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @php
                        // `position` は `#[Locked]` を付けられない（セレクトと双方向に束ねる）ため、
                        // `/livewire/update` から任意の文字列が入りうる。`from()` は不一致で
                        // ValueError を投げ、以後この画面が描画できなくなるため `tryFrom()` で
                        // 許可値と照合する（17.5.1-2。一覧の絞り込みと同じ扱い）。
                        $selectedPosition = \App\Enums\BannerPosition::tryFrom($position);
                    @endphp
                    @if ($selectedPosition !== null)
                        <flux:text size="sm">
                            {{ __('admin.banner.notices.recommended_size', ['width' => $selectedPosition->recommendedWidth(), 'height' => $selectedPosition->recommendedHeight()]) }}
                        </flux:text>
                    @endif
                </div>
                <flux:error name="position" />

                <div>
                    @if ($banner_id !== null && $editingBanner !== null)
                        {{-- 画像の実在確認（ファイルの stat）を1回に抑えるため、URLは変数に受ける。 --}}
                        @php $editingImageUrl = $editingBanner->imageUrl(); @endphp
                        <div class="mb-2">
                            @if ($editingImageUrl !== null)
                                <img src="{{ $editingImageUrl }}" alt="{{ $editingBanner->alt }}" class="h-16 w-auto" />
                            @else
                                <x-banner-placeholder :position="$editingBanner->position" class="w-40" />
                            @endif
                        </div>
                    @endif
                    <flux:input type="file" wire:model="image" :label="__('admin.banner.fields.image')" />
                    <flux:text size="sm">{{ __('admin.banner.notices.image') }}</flux:text>
                    @if ($banner_id !== null)
                        <flux:text size="sm">{{ __('admin.banner.notices.image_optional') }}</flux:text>
                    @endif
                </div>
                <flux:error name="image" />

                <div>
                    <flux:input wire:model="link_url" :label="__('admin.banner.fields.link_url')" />
                    <flux:text size="sm">{{ __('admin.banner.notices.link_url') }}</flux:text>
                </div>
                <flux:error name="link_url" />

                <div>
                    <flux:input wire:model="alt" :label="__('admin.banner.fields.alt')" required />
                    <flux:text size="sm">{{ __('admin.banner.notices.alt') }}</flux:text>
                </div>
                <flux:error name="alt" />

                <flux:input type="number" wire:model="sort_order" :label="__('admin.banner.fields.sort_order')" required />
                <flux:error name="sort_order" />

                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input type="datetime-local" wire:model="starts_at" :label="__('admin.banner.fields.starts_at')" />
                        <flux:input type="datetime-local" wire:model="ends_at" :label="__('admin.banner.fields.ends_at')" />
                    </div>
                    <flux:text size="sm">{{ __('admin.banner.notices.period') }}</flux:text>
                </div>
                <flux:error name="starts_at" />
                <flux:error name="ends_at" />

                <flux:select wire:model="cinema_id" :label="__('admin.banner.fields.cinema')">
                    <flux:select.option value="">{{ __('admin.banner.all_cinemas') }}</flux:select.option>
                    @foreach ($cinemas as $cinema)
                        <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="cinema_id" />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancel">{{ __('admin.banner.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.banner.actions.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
