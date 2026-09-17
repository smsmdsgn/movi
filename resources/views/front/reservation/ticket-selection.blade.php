{{--
    券種選択（P-35、7.10）の Livewire ビュー。

    render() から渡る変数:
    - $onSale: 販売期間内の上映回が存在するか（4.3.1 / 4.3.10）
    - $canProceed: 先へ進める前提（販売期間・座席の保持・利用規約への同意）が揃っているか
    - $noticeKey: 表示する案内の文言キー（7.17）。無い場合は null
    - $recoveryUrl / $recoveryLabelKey: 前提を満たしていない場合の復帰先（4.3.12）
    - $seats: 保持中の座席（座席表と同じ並び順）
    - $ticketTypes: 券種マスタ（6.5.1、display_order 順）
    - $breakdown: PriceBreakdown。未選択の座席がある間は null（4.3.13）
    - $seatsUrl: 座席選択（P-31）のURL。戻り先は P-31 に寄せる（P-33 は前送りするため。4.3.13）

    ライブリージョンはルート直下に常設し、中身だけを差し替える（P-32〜P-34 と同じ扱い）。

    Livewire の制約により、ルート要素は1つの <div> とする。
--}}
@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Seat> $seats */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\TicketType> $ticketTypes */
    /** @var \App\Services\PriceBreakdown|null $breakdown */
@endphp
<div>
    <div role="alert" aria-live="assertive" class="empty:hidden">
        @if ($noticeKey !== null)
            {{-- 販売期間外・削除済みの回は利用者の操作の失敗ではないため中立の配色にする（4.3.10）。 --}}
            <p class="mb-4 border p-3 text-sm {{ $onSale ? 'border-red-700 bg-red-50 text-red-900' : 'border-stone-300 bg-stone-100' }}">
                {{ __($noticeKey) }}
            </p>
        @endif
    </div>

    @if (! $canProceed)
        {{-- 前提を満たしていない場合は選択を求めず、復帰先だけを残す（4.3.12）。 --}}
        @if ($recoveryUrl !== null)
            <p>
                <a href="{{ $recoveryUrl }}" class="inline-block border border-stone-400 px-4 py-2 text-sm underline decoration-stone-400 hover:bg-stone-100">
                    {{ __($recoveryLabelKey) }}
                </a>
            </p>
        @endif
    @else
        <form wire:submit="submit">
            {{-- 7.10-1・7.10-2 選択した座席と、座席ごとの券種 --}}
            <section aria-labelledby="tickets-heading" class="border border-stone-300 p-4">
                <h2 id="tickets-heading" class="font-bold">{{ __('front.reservation.tickets.seats_heading') }}</h2>
                <p class="mt-1 text-sm text-stone-600">{{ __('front.reservation.tickets.lead') }}</p>

                <ul class="mt-4 space-y-3">
                    @foreach ($seats as $seat)
                        @php $inputId = 'ticket-'.$seat->id; @endphp
                        <li class="flex flex-wrap items-center gap-3 border-b border-stone-200 pb-3 last:border-b-0 last:pb-0">
                            <label for="{{ $inputId }}" class="w-24 font-bold tabular-nums">{{ $seat->displayName() }}</label>

                            <select
                                id="{{ $inputId }}"
                                wire:model.live="selections.{{ $seat->id }}"
                                class="grow border border-stone-400 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                            >
                                {{-- 既定を未選択にする。券種は価格が異なるため、初期値を入れると
                                     選んだつもりのない金額で進めてしまう（7.10 のバリデーション）。 --}}
                                <option value="">{{ __('front.reservation.tickets.unselected') }}</option>
                                @foreach ($ticketTypes as $ticketType)
                                    <option value="{{ $ticketType->id }}">
                                        {{ __('front.reservation.tickets.option', ['name' => $ticketType->name, 'price' => number_format($ticketType->price)]) }}
                                    </option>
                                @endforeach
                            </select>
                        </li>
                    @endforeach
                </ul>

                {{-- 座席種別の追加料金（6.5.3）は券種の価格に含まれないため、内訳で示す。 --}}
                <p class="mt-4 text-xs text-stone-600">{{ __('front.reservation.tickets.surcharge_note') }}</p>
            </section>

            {{-- 券種の適用条件（6.5.1、`m_ticket_types.condition`）。4.8.6追記表が「券種選択（7.10）と
                 料金表（4.9.1）に出す案内文」と定めている。当日に証明できない券種を選ばせないため、
                 購入前に見せる。セレクトの選択肢ごとに繰り返すと8席ぶん冗長になるので、一覧で1度だけ出す。 --}}
            @php $conditioned = $ticketTypes->filter(fn ($ticketType) => filled($ticketType->condition)); @endphp
            @if ($conditioned->isNotEmpty())
                <section aria-labelledby="conditions-heading" class="mt-4 border border-stone-300 bg-stone-50 p-4">
                    <h2 id="conditions-heading" class="text-sm font-bold">{{ __('front.reservation.tickets.conditions_heading') }}</h2>
                    <dl class="mt-2 space-y-1 text-xs text-stone-700">
                        @foreach ($conditioned as $ticketType)
                            <div class="flex gap-2">
                                <dt class="shrink-0 font-bold">{{ $ticketType->name }}</dt>
                                <dd>{{ $ticketType->condition }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            {{-- 7.10-3・7.10-5 割引と金額 --}}
            <section aria-labelledby="amount-heading" aria-live="polite" class="mt-6 border border-stone-300 p-4">
                <h2 id="amount-heading" class="font-bold">{{ __('front.reservation.tickets.amount_heading') }}</h2>

                @if ($breakdown === null)
                    {{-- 一部の席だけで計算した小計を出すと、割引の成否（ペア割は大人の枚数で
                         決まる）が選択の途中で変わって見える（4.3.13）。 --}}
                    <p class="mt-2 text-sm text-stone-600">{{ __('front.reservation.tickets.amount_pending') }}</p>
                @else
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt>{{ __('front.reservation.tickets.subtotal') }}</dt>
                            <dd class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($breakdown->subtotal())]) }}</dd>
                        </div>

                        @if ($breakdown->discount !== null)
                            <div class="flex justify-between text-red-900">
                                <dt>{{ $breakdown->discount->label() }}</dt>
                                <dd class="tabular-nums">−{{ __('front.reservation.yen', ['amount' => number_format($breakdown->discountAmount())]) }}</dd>
                            </div>
                        @endif

                        <div class="flex justify-between border-t border-stone-300 pt-2 text-base font-bold">
                            <dt>{{ __('front.reservation.tickets.total') }}</dt>
                            <dd class="tabular-nums">{{ __('front.reservation.yen', ['amount' => number_format($breakdown->total())]) }}</dd>
                        </div>
                    </dl>
                @endif
            </section>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <a href="{{ $seatsUrl }}" class="border border-stone-400 px-4 py-3 text-sm underline decoration-stone-400 hover:bg-stone-100">
                    {{ __('front.reservation.back_to_seats') }}
                </a>

                <button
                    type="submit"
                    class="bg-red-800 px-6 py-3 font-bold text-white hover:bg-red-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
                >
                    {{ __('front.reservation.tickets.proceed') }}
                </button>
            </div>
        </form>
    @endif
</div>
