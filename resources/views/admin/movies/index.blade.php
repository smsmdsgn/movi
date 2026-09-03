@php
    $currentAdmin = auth('admin')->user();
    $canCreate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('create', \App\Models\Movie::class);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('admin.movie.title') }}</flux:heading>

        @if ($canCreate)
            <flux:button variant="primary" wire:click="openImport">{{ __('admin.movie.actions.import') }}</flux:button>
        @endif
    </div>

    <flux:text>{{ __('admin.movie.runtime_notice') }}</flux:text>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('admin.movie.fields.poster') }}</flux:table.column>
            <flux:table.column>{{ __('admin.movie.fields.title') }}</flux:table.column>
            <flux:table.column>{{ __('admin.movie.fields.released_on') }}</flux:table.column>
            <flux:table.column>{{ __('admin.movie.fields.runtime_minutes') }}</flux:table.column>
            <flux:table.column>{{ __('admin.movie.fields.genres') }}</flux:table.column>
            <flux:table.column>{{ __('admin.movie.fields.format_ids') }}</flux:table.column>
            <flux:table.column>{{ __('admin.movie.actions.label') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($movies as $movie)
                @php
                    $canUpdate = \Illuminate\Support\Facades\Gate::forUser($currentAdmin)->allows('update', $movie);
                    $posterUrl = $movie->posterUrl();
                @endphp
                <flux:table.row :key="$movie->id">
                    <flux:table.cell>
                        @if ($posterUrl)
                            <img src="{{ $posterUrl }}" alt="{{ __('admin.movie.poster_alt', ['title' => $movie->title]) }}" loading="lazy" class="h-16 w-auto rounded">
                        @else
                            {{ __('admin.movie.no_poster') }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-col">
                            <span>{{ $movie->title }}</span>
                            @if ($movie->original_title)
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $movie->original_title }}</span>
                            @endif
                            {{-- 初期データの架空作品（9.1、ダミーのtmdb_id）はTMDB上に作品ページを持たない。 --}}
                            @if ($movie->hasTmdbPage())
                                <a href="{{ $movie->tmdbUrl() }}" target="_blank" rel="noopener noreferrer" class="text-xs text-blue-600 hover:underline dark:text-blue-400">{{ __('admin.movie.tmdb_link') }}</a>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $movie->released_on->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>{{ __('admin.movie.minutes', ['minutes' => $movie->runtime_minutes]) }}</flux:table.cell>
                    <flux:table.cell>{{ $movie->genres ? implode('、', $movie->genres) : '' }}</flux:table.cell>
                    <flux:table.cell>{{ implode('、', $movie->formats->pluck('name')->all()) }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($canUpdate)
                            <flux:button size="sm" wire:click="edit({{ $movie->id }})">{{ __('admin.movie.actions.edit') }}</flux:button>
                        @else
                            <flux:button size="sm" wire:click="view({{ $movie->id }})">{{ __('admin.movie.actions.view') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:pagination :paginator="$movies" />

    <flux:modal wire:model.self="showForm" class="md:w-[40rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading level="2">
                @if ($readOnly)
                    {{ __('admin.movie.actions.view') }}
                @elseif ($movie_id === null)
                    {{ __('admin.movie.actions.import') }}
                @else
                    {{ __('admin.movie.actions.edit') }}
                @endif
            </flux:heading>

            {{-- tmdb_id は取り込み時に確定する表示専用の値（wire:model を持たない）。
                 一意制約違反等のエラーが無言で失われないよう、表示先を明示的に置く。 --}}
            <flux:input :value="$tmdb_id" :label="__('admin.movie.fields.tmdb_id')" disabled />
            <flux:error name="tmdb_id" />
            <flux:input wire:model="title" :label="__('admin.movie.fields.title')" :disabled="$readOnly" required />
            <flux:input wire:model="original_title" :label="__('admin.movie.fields.original_title')" :disabled="$readOnly" />
            <flux:textarea wire:model="synopsis" :label="__('admin.movie.fields.synopsis')" :disabled="$readOnly" required>{{ $synopsis }}</flux:textarea>
            <flux:input wire:model="poster_path" :label="__('admin.movie.fields.poster_path')" :disabled="$readOnly" />
            <flux:input wire:model="runtime_minutes" :label="__('admin.movie.fields.runtime_minutes')" type="number" min="1" :disabled="$readOnly" required />
            <flux:input wire:model="released_on" :label="__('admin.movie.fields.released_on')" type="date" :disabled="$readOnly" required />
            <flux:input wire:model="genres" :label="__('admin.movie.fields.genres')" :description="__('admin.movie.genres_hint')" :disabled="$readOnly" />

            {{-- wire:model はグループ側にのみ置く。子の <flux:checkbox> にも付けると
                 ui-checkbox-group による同期と個々のバインディングが二重に発火する。 --}}
            <flux:checkbox.group wire:model="format_ids" :label="__('admin.movie.fields.format_ids')">
                @foreach ($formats as $format)
                    <flux:checkbox :value="$format->id" :label="$format->name" :disabled="$readOnly" />
                @endforeach
            </flux:checkbox.group>

            <div class="flex justify-end gap-2">
                @if ($readOnly)
                    <flux:button type="button" wire:click="cancel">{{ __('admin.movie.actions.close') }}</flux:button>
                @else
                    <flux:button type="button" wire:click="cancel">{{ __('admin.movie.actions.cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('admin.movie.actions.save') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>

    @if ($canCreate)
        <flux:modal wire:model.self="showImportForm" class="md:w-[40rem]">
            <div class="flex flex-col gap-6">
                <flux:heading level="2">{{ __('admin.movie.actions.import') }}</flux:heading>
                <flux:text>{{ __('admin.movie.import_hint') }}</flux:text>

                <form wire:submit="search" class="flex items-end gap-2">
                    <flux:input wire:model="searchQuery" :label="__('admin.movie.fields.searchQuery')" class="flex-1" />
                    <flux:button type="submit" variant="primary">{{ __('admin.movie.actions.search') }}</flux:button>
                </form>

                @if ($searchError)
                    <flux:callout variant="danger" :text="$searchError" />
                @endif

                @if ($searched && $searchResults === [] && $searchError === null)
                    <flux:text>{{ __('admin.movie.search_empty') }}</flux:text>
                @endif

                @if ($searchResults !== [])
                    <div class="flex flex-col gap-3">
                        @foreach ($searchResults as $result)
                            <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="tmdb-result-{{ $result['tmdb_id'] }}">
                                @if ($result['poster_url'])
                                    <img src="{{ $result['poster_url'] }}" alt="{{ __('admin.movie.poster_alt', ['title' => $result['title']]) }}" loading="lazy" class="h-20 w-auto rounded">
                                @endif
                                <div class="flex flex-1 flex-col">
                                    <span>{{ $result['title'] }}</span>
                                    @if ($result['original_title'])
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $result['original_title'] }}</span>
                                    @endif
                                    @if ($result['released_on'])
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $result['released_on'] }}</span>
                                    @endif
                                </div>
                                @if ($result['registered'])
                                    <flux:badge color="zinc">{{ __('admin.movie.registered') }}</flux:badge>
                                @else
                                    <flux:button size="sm" wire:click="import({{ $result['tmdb_id'] }})">{{ __('admin.movie.actions.select') }}</flux:button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-end">
                    <flux:button type="button" wire:click="cancelImport">{{ __('admin.movie.actions.cancel') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
