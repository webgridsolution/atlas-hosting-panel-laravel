<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer portal assumes the signed-in user owns a Customer record.
 *
 * Staff accounts deliberately do not have one — a support engineer is not a
 * paying customer — so every customer controller used to reach
 * `$request->user()->customer->…` and throw on null, turning a wrong-URL click
 * into a 500. This middleware answers the question once, in one place:
 *
 *   • staff            → sent to the control plane, where their own screens are;
 *   • a user with no customer profile and no staff role (a half-created account,
 *     a deleted customer) → 403 with an explanation, not a stack trace.
 *
 * The alternative — an `abort_if` in each of the eight controllers — is how one
 * screen gets forgotten during a refactor.
 */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->customer !== null) {
            return $next($request);
        }

        if ($user->isStaff()) {
            return redirect()->route('admin.dashboard');
        }

        abort(403, 'This account has no customer profile attached. Contact support.');
    }
}
