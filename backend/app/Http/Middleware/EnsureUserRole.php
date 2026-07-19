<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if ($roles === []) {
            return $next($request);
        }

        $allowed = array_map(
            static fn (string $role): string => UserRole::tryFromMixed($role)->value,
            $roles,
        );

        if (! in_array($user->role, $allowed, true)) {
            abort(403, 'You are not authorized to perform this action.');
        }

        return $next($request);
    }
}
