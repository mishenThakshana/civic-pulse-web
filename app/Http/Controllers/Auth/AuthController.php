<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'These credentials do not match our records.',
            ])->onlyInput('email');
        }

        $user = Auth::user();

        if (! $user->canAccessAdminPanel() || ! $user->is_active) {
            Auth::logout();

            return back()->withErrors([
                'email' => 'This account does not have access to the admin panel.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        ActivityLog::record(ActivityAction::SignedIn, "{$user->name} signed in.", $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();
        ActivityLog::record(ActivityAction::SignedOut, "{$user->name} signed out.", $user);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
