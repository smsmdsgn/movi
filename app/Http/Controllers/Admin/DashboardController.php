<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * 管理画面ダッシュボード（A-02）。集計項目（7.16）は上映回・予約の管理画面
 * （後続タスク）が無いと算出できないため、現時点では案内表示のみとする。
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard');
    }
}
