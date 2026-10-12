<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log out a user whose account was deactivated while their session was open.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = 'Akun Anda telah dinonaktifkan. Hubungi administrator.';

        if ($request->expectsJson()) {
            abort(Response::HTTP_UNAUTHORIZED, $message);
        }

        return redirect()->route('login')->withErrors(['username' => $message]);
    }
}
