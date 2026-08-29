<?php

namespace App\Http\Middleware;

use App\Services\TallySync\TallyConnectorAuth;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTallyConnectorToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            abort(403, 'A Tally connector token is required.');
        }

        if ($token->name !== TallyConnectorAuth::TOKEN_NAME
            || ! $token->can(TallyConnectorAuth::ABILITY)) {
            abort(403, 'A Tally connector token is required.');
        }

        return $next($request);
    }
}
