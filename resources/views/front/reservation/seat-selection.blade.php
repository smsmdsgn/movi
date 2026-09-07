{{--
    座席選択（P-31、7.6）の Livewire ビュー。

    render() から渡る変数:
    - $onSale: 販売期間内か（4.3.1）。期間外は座席表を出さず案内のみを表示する
    - $seats: 描画する座席（使用可能な座席のみ。grid_row → grid_col 順）
    - $states: 座席IDごとの `SeatSelectionState`
    - $selectedSeats: 保持中（選択中）の座席
    - $selectedCount: 保持中の座席数（ロックの実体を正とする。$selectedSeats の件数ではない）
    - $maxSeats: 1度に選択できる座席数の上限
    - $columnCount: 座席表の列数（`grid_col` の最大値）

    描画は CSS Grid（6.3.1 描画方針3・4。SVG を使用しない）。通路と使用不可の座席は
    レコードを持たず、座標の空きとして表現される。

    座席の状態は色のみで区別せず（5.2-3 / 13.5-5）、記号（✓ / ×）と読み上げ用の
    aria-label を併記する。選択不可の座席も `disabled` にせずフォーカスを当てられる
    ようにする（7.6.4-3。`disabled` な button はフォーカスを受け取れない）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
@php
    use App\Enums\SeatDisplayClass;
    use App\Enums\SeatSelectionState;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Seat> $seats */
    /** @var \Illuminate\Support\Collection<int, \App\Enums\SeatSelectionState> $states */

    /** 座席種別の記号（18.2）。一般席は記号を持たない。 */
    $typeMarker = fn (SeatDisplayClass $class): string => match ($class) {
        SeatDisplayClass::Wheelchair => '♿',
        SeatDisplayClass::Executive => 'EXE',
        SeatDisplayClass::Standard => '',
    };

    /**
     * マスの配色。状態を優先し、エグゼクティブ席は選択可のときのみ別色にする。
     * 18.2 は車椅子席をグレーとするが、グレーは「選択できない座席」の色でもあるため、
     * 空いている車椅子席が選択不可に見える。車椅子席は記号（♿）で区別する（4.3.9）。
     */
    $stateClass = fn (SeatSelectionState $state, SeatDisplayClass $class): string => match (true) {
        $state === SeatSelectionState::Selected => 'border-2 border-blue-950 bg-blue-800 text-white',
        $state === SeatSelectionState::Occupied => 'border border-stone-400 bg-stone-200 text-stone-700',
        $class === SeatDisplayClass::Executive => 'border border-amber-700 bg-amber-100 text-amber-950 hover:bg-amber-200',
        default => 'border border-blue-800 bg-blue-100 text-blue-950 hover:bg-blue-200',
    };

    $rowLabels = $seats->groupBy('grid_row')->map(fn ($rowSeats): string => $rowSeats->first()->row_label);
@endphp
{{-- ポーリング（6.4.3-1）は名前付きのメソッドを呼ぶ。利用者の操作と区別できる呼び出し口が
     無いと、確保期限の切れた座席が無言で外れる（`refreshSeatMap()` を参照）。 --}}
<div wire:poll.10s="refreshSeatMap">
    @if (! $onSale)
        <p class="border border-stone-300 bg-stone-100 p-4 text-sm" role="status">
            {{ __('front.reservation.errors.out_of_sale') }}
        </p>
    @else
        {{-- live region は常設し、中身だけを差し替える。要素ごと出し入れすると、
             同じ文言が続いたときに読み上げられない実装がある。 --}}
        <div role="alert" aria-live="assertive" class="empty:hidden">
            @if ($messageKey !== null)
                <p class="mb-4 border border-red-700 bg-red-50 p-3 text-sm text-red-900">
                    {{ __($messageKey, ['max' => $maxSeats]) }}
                </p>
            @endif
        </div>

        <div wire:loading.class="opacity-50" wire:target="toggle" class="transition-opacity">
            {{-- スクリーンの位置（7.6.1-2）。座席表は最前列（A列）を上端に描画する。 --}}
            <p class="border-b-4 border-brand bg-stone-100 py-1 text-center text-xs tracking-[0.5em] text-stone-600">
                {{ __('front.reservation.screen') }}
            </p>

            {{-- 大きなシアターでは列数が画面幅を超えるため、座席表のみ横スクロールさせる。 --}}
            <div class="mt-4 overflow-x-auto pb-2">
                <div
                    role="group"
                    aria-label="{{ __('front.reservation.seat_map_label') }}"
                    class="mx-auto grid w-fit gap-1"
                    {{-- 行は暗黙行のため、座席レコードを持たない横通路（6.3.1 規則2・6）は
                         高さを指定しないと潰れて通路に見えない。座席の高さ（h-9）と揃える。 --}}
                    style="grid-template-columns: 1.5rem repeat({{ $columnCount }}, 2rem); grid-auto-rows: 2.25rem;"
                >
                    @foreach ($rowLabels as $gridRow => $rowLabel)
                        <span
                            wire:key="row-{{ $gridRow }}"
                            aria-hidden="true"
                            style="grid-row: {{ $gridRow }}; grid-column: 1;"
                            class="flex items-center justify-center text-[10px] text-stone-500"
                        >{{ $rowLabel }}</span>
                    @endforeach

                    @foreach ($seats as $seat)
                        @php
                            $state = $states[$seat->id];
                            $displayClass = $seat->seatType->display_class;
                            $typeName = $displayClass === SeatDisplayClass::Standard ? '' : $seat->seatType->name;
                            $marker = $state->symbol().$typeMarker($displayClass);
                            $ariaLabel = preg_replace('/\s+/u', ' ', trim(__('front.reservation.seat_label', [
                                'seat' => $seat->displayName(),
                                'type' => $typeName,
                                'state' => __($state->labelKey()),
                            ])));
                        @endphp
                        <button
                            type="button"
                            wire:key="seat-{{ $seat->id }}"
                            @if ($state->isOperable())
                                wire:click="toggle({{ $seat->id }})"
                                aria-pressed="{{ $state === SeatSelectionState::Selected ? 'true' : 'false' }}"
                            @else
                                {{-- 選択できない座席は切替ボタンではないため aria-pressed を持たせない。
                                     フォーカスは当てられるようにする（7.6.4-3）。 --}}
                                aria-disabled="true"
                            @endif
                            aria-label="{{ $ariaLabel }}"
                            data-seat-state="{{ $state->value }}"
                            style="grid-row: {{ $seat->grid_row }}; grid-column: {{ $seat->grid_col + 1 }};"
                            class="flex h-9 flex-col items-center justify-center focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 {{ $stateClass($state, $displayClass) }}"
                        >
                            <span aria-hidden="true" class="text-[10px] leading-none tabular-nums">{{ $seat->seat_number }}</span>
                            @if ($marker !== '')
                                <span aria-hidden="true" class="text-[10px] leading-none">{{ $marker }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- 凡例（7.6.1-4） --}}
        <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-xs">
            <div>
                <span class="text-stone-600">{{ __('front.reservation.legend.state') }}:</span>
                @foreach (SeatSelectionState::cases() as $legendState)
                    <span class="ml-2 inline-flex items-center gap-1">
                        <span aria-hidden="true" class="inline-flex h-5 w-5 items-center justify-center text-[10px] {{ $stateClass($legendState, SeatDisplayClass::Standard) }}">{{ $legendState->symbol() }}</span>
                        {{ __($legendState->labelKey()) }}
                    </span>
                @endforeach
            </div>
            <div>
                <span class="text-stone-600">{{ __('front.reservation.legend.seat_type') }}:</span>
                @foreach ($seats->pluck('seatType')->unique('id')->sortBy('id') as $seatType)
                    <span class="ml-2 inline-flex items-center gap-1">
                        <span aria-hidden="true" class="inline-flex h-5 min-w-5 items-center justify-center px-1 text-[10px] {{ $stateClass(SeatSelectionState::Selectable, $seatType->display_class) }}">{{ $typeMarker($seatType->display_class) }}</span>
                        {{ $seatType->name }}
                    </span>
                @endforeach
            </div>
        </div>

        {{-- 座席レイアウト図（7.6.3）。ポーリングによる再描画で開いたモーダルが閉じないよう
             wire:ignore を付ける（内容は静的であり差分更新の必要が無い）。 --}}
        <div class="mt-4" wire:ignore x-data>
            <button
                type="button"
                x-on:click="$refs.layoutFigure.showModal()"
                class="border border-stone-400 px-3 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100"
            >
                {{ __('front.reservation.layout_figure.open') }}
            </button>

            <dialog
                x-ref="layoutFigure"
                aria-labelledby="layout-figure-heading"
                class="w-[90vw] max-w-2xl border border-stone-400 p-0 backdrop:bg-black/50"
            >
                <div class="p-4">
                    <h2 id="layout-figure-heading" class="text-lg font-bold">{{ __('front.reservation.layout_figure.heading') }}</h2>
                    <p class="mt-2 text-sm text-stone-600">{{ __('front.reservation.layout_figure.note') }}</p>
                    {{-- 図の画像素材は未用意（12章 残課題3）。用意でき次第ここへ配置する。 --}}
                    <p class="mt-4 border border-dashed border-stone-400 p-6 text-center text-sm text-stone-600">
                        {{ __('front.reservation.layout_figure.pending') }}
                    </p>
                    <div class="mt-4 text-right">
                        <button
                            type="button"
                            x-on:click="$refs.layoutFigure.close()"
                            class="border border-stone-400 px-4 py-2 text-sm hover:bg-stone-100"
                        >
                            {{ __('front.reservation.layout_figure.close') }}
                        </button>
                    </div>
                </div>
            </dialog>
        </div>

        {{-- 選択中の座席と枚数（7.6.1-6）。画面下部に固定表示する。 --}}
        <div class="sticky bottom-0 mt-6 border-t border-stone-300 bg-white py-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="text-sm">
                    <span class="text-stone-600">{{ __('front.reservation.selected.heading') }}:</span>
                    @if ($selectedSeats->isEmpty())
                        <span>{{ __('front.reservation.selected.none') }}</span>
                    @else
                        <span class="font-bold tabular-nums">{{ $selectedSeats->map(fn ($seat) => $seat->displayName())->implode('・') }}</span>
                        <span class="tabular-nums">（{{ __('front.reservation.selected.count', ['count' => $selectedCount]) }}）</span>
                    @endif
                    <span class="ml-2 block text-xs text-stone-600 md:inline">{{ __('front.reservation.selected.max_note', ['max' => $maxSeats]) }}</span>
                </div>

                <button
                    type="button"
                    wire:click="proceed"
                    class="bg-red-800 px-6 py-3 font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                >
                    {{ __('front.reservation.proceed') }}
                </button>
            </div>
        </div>
    @endif
</div>
