<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Format;
use App\Models\SeatType;
use App\Models\TicketType;
use Illuminate\View\View;

/**
 * 料金表・割引サービス（P-08、7.1.1 / 4.9.1 / 6.5）。
 *
 * 券種・上映規格・座席の追加料金はいずれもマスタから動的に表示する（4.9.1
 * 「料金表ページは券種マスタから動的に生成する」）。割引額は `PricingService` の
 * 定数を参照し、直書きしない（6.5.2 / pricing スキル）。
 *
 * 館はビューで扱わない。ヘッダーが `CurrentCinemaService` で自前に解決する
 * （P-07 と同じ。館ごとの料金差は無いため本ページも館非依存とする。6.5「館ごとの料金差は
 * 設けず、全館共通とする」）。
 */
class PriceController extends Controller
{
    public function __invoke(): View
    {
        return view('front.pages.prices', [
            'ticketTypes' => TicketType::orderBy('display_order')->orderBy('id')->get(),
            'formats' => Format::orderBy('id')->get(),
            'surchargedSeatTypes' => SeatType::where('surcharge', '>', 0)->orderBy('id')->get(),
        ]);
    }
}
