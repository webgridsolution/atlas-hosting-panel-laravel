<?php

use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\EnsureStaff;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => EnsureStaff::class,
            'customer' => EnsureCustomer::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Payment webhooks are authenticated by HMAC over the raw body, not by session/CSRF.
        $middleware->validateCsrfTokens(except: ['webhooks/razorpay']);

        // A signed-in visitor who opens /login or /register goes to the console
        // that matches their role. Without this, staff land on /dashboard — a
        // customer-only screen — and are shown a 403 after a successful login.
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->isStaff()
            ? route('admin.dashboard')
            : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
