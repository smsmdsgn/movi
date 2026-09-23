@php
    $currentAdmin = auth('admin')->user();
    $canSelectCinema = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('viewAllCinemas', \App\Models\Cinema::class);
    $canEditAny = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('updateAny', \App\Models\Post::class);
    // 全館共通を選べる＝館を選べる管理者（役割名では分岐しない。4.7.4追記表）。
    $canAssignCinema = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('assignCinema', [\App\Models\Post::class, null]);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('admin.post.title') }}</flux:heading>

        <div class="flex items-center gap-2">
            @if ($canSelectCinema)
                <flux:select wire:model.live="selectedCinemaId" class="w-56">
                    <flux:select.option value="">{{ __('admin.common.all_cinemas') }}</flux:select.option>
                    @foreach ($cinemas as $cinema)
                        <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="filterCategoryId" class="w-40">
                <flux:select.option value="">{{ __('admin.post.filters.all_categories') }}</flux:select.option>
                @foreach ($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="filterStatus" class="w-32">
                <flux:select.option value="">{{ __('admin.post.filters.all_statuses') }}</flux:select.option>
                <flux:select.option value="{{ \App\Enums\PostStatus::Draft->value }}">{{ __('admin.post.statuses.draft') }}</flux:select.option>
                <flux:select.option value="{{ \App\Enums\PostStatus::Published->value }}">{{ __('admin.post.statuses.published') }}</flux:select.option>
            </flux:select>

            @if ($canEditAny)
                <flux:button variant="primary" wire:click="createPost">{{ __('admin.post.actions.create') }}</flux:button>
            @endif
        </div>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('admin.post.fields.published_at') }}</flux:table.column>
            <flux:table.column>{{ __('admin.post.fields.category') }}</flux:table.column>
            <flux:table.column>{{ __('admin.post.fields.title') }}</flux:table.column>
            <flux:table.column>{{ __('admin.post.fields.cinema') }}</flux:table.column>
            <flux:table.column>{{ __('admin.post.fields.status') }}</flux:table.column>
            <flux:table.column>{{ __('admin.post.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($posts as $post)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $post);
                    // 表示だけの4値（下書き／未公開／公開予定／公開）。Policy・DBの状態は2値のまま（4.7.4追記表）。
                    // `published` かつ公開日時が無い行は顧客側に出ない（4.7.1 の公開制御）ため「公開」と表示しない。
                    // A-12 の検証では作れないが、DBを直接操作した場合に現れうる。
                    $isUnpublished = $post->status === \App\Enums\PostStatus::Published && $post->published_at === null;
                    $isScheduled = $post->status === \App\Enums\PostStatus::Published && $post->published_at?->isFuture();
                    $statusLabel = match (true) {
                        $post->status === \App\Enums\PostStatus::Draft => __('admin.post.statuses.draft'),
                        $isUnpublished => __('admin.post.statuses.unpublished'),
                        $isScheduled => __('admin.post.statuses.scheduled'),
                        default => __('admin.post.statuses.published'),
                    };
                @endphp
                <flux:table.row :key="$post->id">
                    <flux:table.cell>
                        {{ $post->published_at?->format('Y-m-d H:i') ?? __('admin.post.notices.no_published_at') }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $post->category->name }}</flux:table.cell>
                    <flux:table.cell>{{ $post->title }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $post->cinema_id === null ? __('admin.post.all_cinemas') : $post->cinema->name }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $statusLabel }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate)
                            <flux:button size="sm" wire:click="editPost({{ $post->id }})">{{ __('admin.post.actions.edit') }}</flux:button>
                        @else
                            <flux:text size="sm" variant="subtle">{{ __('admin.post.notices.read_only') }}</flux:text>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('admin.post.notices.empty') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:pagination :paginator="$posts" />

    @if ($canEditAny)
        <flux:modal wire:model.self="showForm" class="md:w-[36rem]">
            <form wire:submit="save" class="flex flex-col gap-6">
                <flux:heading level="2">
                    @if ($post_id === null)
                        {{ __('admin.post.actions.create') }}
                    @else
                        {{ __('admin.post.actions.edit') }}
                    @endif
                </flux:heading>

                <flux:select wire:model="category_id" :label="__('admin.post.fields.category')">
                    @foreach ($categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="category_id" />

                @if ($canAssignCinema)
                    <flux:select wire:model="cinema_id" :label="__('admin.post.fields.cinema')">
                        <flux:select.option value="">{{ __('admin.post.all_cinemas') }}</flux:select.option>
                        @foreach ($assignableCinemas as $cinema)
                            <flux:select.option value="{{ $cinema->id }}">{{ $cinema->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="cinema_id" />
                @endif

                <flux:input wire:model="title" :label="__('admin.post.fields.title')" required />
                <flux:error name="title" />

                <div>
                    <flux:textarea wire:model="body" :label="__('admin.post.fields.body')" rows="12" required />
                    <flux:text size="sm">{{ __('admin.post.notices.markdown') }}</flux:text>
                </div>
                <flux:error name="body" />

                <flux:select wire:model="status" :label="__('admin.post.fields.status')">
                    <flux:select.option value="{{ \App\Enums\PostStatus::Draft->value }}">{{ __('admin.post.statuses.draft') }}</flux:select.option>
                    <flux:select.option value="{{ \App\Enums\PostStatus::Published->value }}">{{ __('admin.post.statuses.published') }}</flux:select.option>
                </flux:select>
                <flux:error name="status" />

                <div>
                    <flux:input type="datetime-local" wire:model="published_at" :label="__('admin.post.fields.published_at')" />
                    <flux:text size="sm">{{ __('admin.post.notices.published_at') }}</flux:text>
                </div>
                <flux:error name="published_at" />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancel">{{ __('admin.post.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.post.actions.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
