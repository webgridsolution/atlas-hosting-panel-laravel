<?php

use App\Http\Middleware\EnsureLicenceAllowsWrites;
use App\Http\Middleware\EnsureMailEnabled;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'licence.writes' => EnsureLicenceAllowsWrites::class,
            'mail.on' => EnsureMailEnabled::class,
        ]);

        // The panel is served behind nginx on 2087/2083/2096; trusting the
        // proxy makes the client IP correct in logs and rate limits.
        $middleware->trustProxies(at: '*');

        // The global TrimStrings middleware would strip the leading and
        // trailing whitespace of every file a customer saves through the
        // editor, and a trailing newline matters in PHP, shell scripts and
        // .htaccess. File content is data, not user-typed input.
        $middleware->trimStrings(except: [
            'content',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
