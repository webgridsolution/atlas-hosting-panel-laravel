<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\LicenseManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks state changes when the licence is not usable, while leaving every
 * read-only screen available. This is the policy that keeps an expired licence
 * from becoming an outage: websites keep serving, the operator can log in and
 * fix the licence, and nothing is deleted.
 */
class EnsureLicenceAllowsWrites
{
    public function __construct(private readonly LicenseManager $licences) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($this->licences->allowsWrites()) {
            return $next($request);
        }

        $reason = $this->licences->evaluate()['reason'];

        if ($request->expectsJson()) {
            return response()->json(['message' => $reason], 423);
        }

        return back()->with('error', $reason.' Existing websites keep serving.');
    }
}
