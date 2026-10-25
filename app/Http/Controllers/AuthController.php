<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\IpSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(private readonly IpSecurity $security) {}

    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $ip = $this->security->clientIp($request);

        try {
            if ($this->security->isBlocked($ip)) {
                return back()->withInput($request->only('username'))
                    ->with('error', 'Your IP is temporarily blocked.');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $user = User::query()->where('username', $username)->first();
        $ok = $user !== null && Auth::attempt(['username' => $username, 'password' => $password]);

        try {
            $this->security->recordLogin($ip, $username, $ok);
        } catch (\Throwable $e) {
            report($e);
        }

        if (! $ok) {
            return back()->withInput($request->only('username'))
                ->with('error', 'Invalid username or password.');
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
