<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'mobile.session' => \App\Http\Middleware\EnsureActiveMobileSession::class,
        ]);

        // Rewrite generic 401s for revoked mobile tokens → SESSION_REPLACED.
        $middleware->api(append: [
            \App\Http\Middleware\DetectReplacedMobileSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients (including Accept: application/pdf) must get JSON errors,
        // never login HTML redirects that could be saved as a fake .pdf.
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $e,
            \Illuminate\Http\Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            $bearer = $request->bearerToken();
            if ($bearer === null || $bearer === '') {
                return null;
            }

            $sessions = app(\App\Services\Auth\MobileSessionService::class);
            if ($sessions->wasRevokedMobileToken($bearer)) {
                return $sessions->sessionReplacedResponse();
            }

            return null;
        });
    })->create();
