<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for the three interfaces. Passing "reseller" also admits admins,
 * because an admin can look at anything a reseller can; the reverse is never
 * true.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->is_active) {
            auth()->logout();

            return redirect()->route('login')->withErrors(['email' => 'This login has been disabled.']);
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }

            if ($role === 'reseller' && $user->hasRole('admin')) {
                return $next($request);
            }
        }

        abort(403, 'Your account does not have access to this area.');
    }
}
