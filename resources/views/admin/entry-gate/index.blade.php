{{--
    入場ゲート（A-16、4.6.3）。館内に設置した端末で常時開いておく画面。

    カメラの起動・QRコードの読み取りは `resources/js/entry-gate.js`（Alpine.js の
    `entryGate` データ）が行い、読み取った入場コードを `admit()` へ渡す。判定結果は
    画面全体を覆う色（可: 緑 / 不可: 赤）で示し、3秒後に自動で読み取り待機へ戻る
    （4.6.3 動作5・6）。**色だけに頼らず、判定の文言も大きく表示する**（5.2）。

    $cinema: ?Cinema（端末の館。super-admin が館を選ぶまでは null）
    $canSelectCinema: bool（super-admin のみ true）
    $cinemas: Collection<Cinema>（$canSelectCinema が true のときのみ使う）
--}}
<div class="flex flex-col gap-4">
    <flux:heading level="1">{{ __('admin.gate.title') }}</flux:heading>

    @if ($canSelectCinema)
        <flux:select wire:model.live="selectedCinemaId" :label="__('admin.gate.select_cinema')" class="w-56">
            <flux:select.option value="">{{ __('admin.gate.select_cinema') }}</flux:select.option>
            @foreach ($cinemas as $selectableCinema)
                <flux:select.option value="{{ $selectableCinema->id }}">{{ $selectableCinema->name }}</flux:select.option>
            @endforeach
        </flux:select>
    @endif

    @if ($cinema === null)
        <flux:text>{{ __('admin.gate.notices.select_cinema') }}</flux:text>
    @else
        <flux:text>{{ __('admin.gate.cinema_label') }}: {{ $cinema->name }}</flux:text>

        {{-- カメラ枠。読み取り・判定はサーバー側（`admit()`）で行うため、この要素自体は
             Livewire の再描画対象にしない（`wire:ignore`。カメラの起動し直しを防ぐ）。 --}}
        <div x-data="entryGate" wire:ignore class="relative mx-auto aspect-video w-full max-w-md overflow-hidden border border-zinc-700 bg-black">
            <video x-ref="video" class="h-full w-full object-cover" muted playsinline></video>

            <p
                x-cloak
                x-show="cameraUnavailable"
                class="absolute inset-0 flex items-center justify-center bg-black/80 p-4 text-center text-sm text-white"
            >
                {{ __('admin.gate.notices.camera_unavailable') }}
            </p>
        </div>

        <flux:text class="text-center">{{ __('admin.gate.notices.scan') }}</flux:text>

        {{-- 手入力モード（4.6.3「手入力モード」）。QRコードが読み取れない場合に使う。 --}}
        <form wire:submit="admitByReservationNo" class="flex flex-wrap items-end gap-3">
            <flux:input
                wire:model="reservationNo"
                :label="__('admin.gate.fields.reservation_no')"
                :description="__('admin.gate.hints.reservation_no')"
                class="w-56"
            />
            <flux:button type="submit" variant="primary">{{ __('admin.gate.actions.admit') }}</flux:button>
        </form>
    @endif

    {{-- 判定結果（4.6.3 動作5・6）。画面全体を覆い、3秒後に自動で消える。
         `wire:key` を通し番号にすることで、同じ判定が続いても表示を作り直し、
         計時（`x-init`）をやり直す。 --}}
    @if ($result)
        <div
            wire:key="result-{{ $resultSequence }}"
            x-data
            x-init="setTimeout(() => $wire.dismiss({{ $resultSequence }}), 3000)"
            role="status"
            aria-live="assertive"
            class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 p-6 text-center text-white {{ $result['admitted'] ? 'bg-green-700' : 'bg-red-700' }}"
        >
            <p class="text-3xl font-bold">{{ $result['message'] }}</p>

            @if ($result['admitted'])
                <dl class="space-y-1 text-lg">
                    <div><dt class="inline">{{ __('admin.gate.result.movie') }}: </dt><dd class="inline">{{ $result['movie'] }}</dd></div>
                    <div><dt class="inline">{{ __('admin.gate.result.starts_at') }}: </dt><dd class="inline">{{ $result['startsAt'] }}</dd></div>
                    <div><dt class="inline">{{ __('admin.gate.result.theater') }}: </dt><dd class="inline">{{ $result['theater'] }}</dd></div>
                    <div><dt class="inline">{{ __('admin.gate.result.seats') }}: </dt><dd class="inline">{{ $result['seats'] }}</dd></div>
                    <div><dt class="inline">{{ __('admin.gate.result.count') }}: </dt><dd class="inline">{{ $result['count'] }}</dd></div>
                </dl>
            @else
                {{-- 予約を示せない拒否（他館・該当なし）は理由のみとする
                     （`EntryOutcome::revealsReservation()`）。name / startsAt が
                     null の項目は行ごと出さない。 --}}
                <dl class="space-y-1 text-lg">
                    @if ($result['name'] !== null)
                        <div><dt class="inline">{{ __('admin.gate.result.name') }}: </dt><dd class="inline">{{ $result['name'] }}</dd></div>
                    @endif
                    @if ($result['startsAt'] !== null)
                        <div><dt class="inline">{{ __('admin.gate.result.starts_at') }}: </dt><dd class="inline">{{ $result['startsAt'] }}</dd></div>
                    @endif
                </dl>
            @endif
        </div>
    @endif
</div>
