<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = strtolower($credentials['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors(['email' => "Too many login attempts. Retry in {$seconds} seconds."]);
        }

        if (!Auth::attempt(['email' => strtolower($credentials['email']), 'password' => $credentials['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 900);
            $this->audit->log('auth.login_failed', 'user', $credentials['email']);
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Invalid credentials or inactive account.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $this->audit->log('auth.login_succeeded', 'user', (string) Auth::id());

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->audit->log('auth.logout', 'user', (string) Auth::id());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
