<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\Auth\MobileSessionService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated Sanctum token is still the user's sole active mobile session.
 */
class EnsureActiveMobileSession
{
    public function __construct(
        private readonly MobileSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if (! in_array($user->role, UserRole::mobileValues(), true)) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if ($token->name !== MobileSessionService::TOKEN_NAME) {
            return $next($request);
        }

        if (! $this->sessions->isActiveMobileToken($user, $token)) {
            return $this->sessions->sessionReplacedResponse();
        }

        $deviceId = $request->header('X-Device-Id');
        if (filled($deviceId)
            && filled($user->active_mobile_device_id)
            && ! hash_equals((string) $user->active_mobile_device_id, trim((string) $deviceId))
        ) {
            return $this->sessions->sessionReplacedResponse();
        }

        return $next($request);
    }
}
