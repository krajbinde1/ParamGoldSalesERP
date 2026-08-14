<?php

namespace App\Http\Middleware;

use App\Services\Auth\MobileSessionService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When Bearer auth fails because the mobile token was revoked by a newer login,
 * return SESSION_REPLACED instead of a generic unauthenticated response.
 */
class DetectReplacedMobileSession
{
    public function __construct(
        private readonly MobileSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (AuthenticationException $exception) {
            if ($this->isReplaced($request)) {
                return $this->sessions->sessionReplacedResponse();
            }

            throw $exception;
        }

        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        $payload = json_decode($response->getContent() ?: '', true);
        if (is_array($payload)
            && ($payload['code'] ?? null) === MobileSessionService::CODE_SESSION_REPLACED) {
            return $response;
        }

        if ($this->isReplaced($request)) {
            return $this->sessions->sessionReplacedResponse();
        }

        return $response;
    }

    private function isReplaced(Request $request): bool
    {
        $bearer = $request->bearerToken();

        return $bearer !== null
            && $bearer !== ''
            && $this->sessions->wasRevokedMobileToken($bearer);
    }
}
