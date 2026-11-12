<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mail module is off by default on a fresh installation.
 *
 * Without this guard the webmail screens still render a mailbox login form on a
 * server that has no IMAP service — a form that can only ever fail. A control
 * panel must not offer a control that cannot work: when mail is disabled the
 * customer gets an explicit explanation and the operator gets told what to turn
 * on, and no login is attempted.
 */
class EnsureMailEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('atlas.mail.enabled')) {
            return $next($request);
        }

        return response()->view('webmail.disabled', [], 200);
    }
}
