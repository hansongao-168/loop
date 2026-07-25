<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View { return view('admin.login'); }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']]);
        $userOk = hash_equals((string) config('services.admin.username'), $data['username']);
        $password = (string) config('services.admin.password');
        if ($password === '' || ! $userOk || ! hash_equals($password, $data['password'])) {
            return back()->withErrors(['username' => '用户名或密码错误。'])->onlyInput('username');
        }
        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);
        return redirect()->route('admin.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
