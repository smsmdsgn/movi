<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        // customer側（webガード）のセッションを共有しているため、Session::invalidate()
        // による全体破棄は行わない。セッション固定対策のID再生成のみ行う（17.1.2-1）。
        $request->session()->regenerate();

        return redirect()->route('admin.login');
    }
}
