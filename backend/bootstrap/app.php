<?php

use App\Http\Middleware\DetectReplacedMobileSession;
use App\Http\Middleware\EnsureActiveMobileSession;
use App\Http\Middleware\EnsureTallyConnectorToken;
use App\Http\Middleware\EnsureUserRole;
use App\Services\Auth\MobileSessionService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'mobile.session' => EnsureActiveMobileSession::class,
            'tally.connector' => EnsureTallyConnectorToken::class,
        ]);

        // Rewrite generic 401s for revoked mobile tokens → SESSION_REPLACED.
        $middleware->api(append: [
            DetectReplacedMobileSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients (including Accept: application/pdf) must get JSON errors,
        // never login HTML redirects that could be saved as a fake .pdf.
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (
            AuthenticationException $e,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            $bearer = $request->bearerToken();
            if ($bearer === null || $bearer === '') {
                return null;
            }

            $sessions = app(MobileSessionService::class);
            if ($sessions->wasRevokedMobileToken($bearer)) {
                return $sessions->sessionReplacedResponse();
            }

            return null;
        });
    })->create();
