<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // A null is_active column (legacy rows) is treated as active; only an explicit
        // false locks an account out.
        abort_unless($user && $user->is_active !== false && $user->isStaff(), 403, 'Staff access required.');

        return $next($request);
    }
}
