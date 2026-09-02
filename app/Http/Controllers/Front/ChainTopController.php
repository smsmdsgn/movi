<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Cinema;
use App\Services\CurrentCinemaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * チェーントップ（P-01）。全館の一覧から館トップ（P-21）へ遷移する導線を提供する。
 * 館の選択自体は館トップへのリンクをたどることで行われ、`ResolveCinema` が
 * セッション・Cookie への保持を行う（4.1.3-1）。
 */
class ChainTopController extends Controller
{
    public function __invoke(Request $request, CurrentCinemaService $resolver): View
    {
        /*
         * このページ自体は「現在の館」を表示しないが、共通レイアウトのヘッダーより先に
         * コンテナへバインドしておく。ヘッダー任せにすると、ページ本体（$slot）が
         * ヘッダーより先に描画される Blade の評価順により、本体側で `Cinema` が
         * 未バインドの状態が一時的に生じうる（13.4.1 追記表377行の「空のモデル」の罠）。
         */
        $resolver->resolve($request);

        return view('front.chain-top', [
            'cinemas' => Cinema::orderBy('id')->get(),
        ]);
    }
}
