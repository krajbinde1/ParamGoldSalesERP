<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Orders\OrderResource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictOrdersOnlyFilamentAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->hasOrdersOnlyFilamentAccess()) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if ($user->usesProductionSupervisorDashboard()) {
            $allowedPrefixes = [
                'admin',
                'admin/orders',
                'admin/logout',
                'livewire',
            ];

            foreach ($allowedPrefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    return $next($request);
                }
            }

            if ($request->expectsJson()) {
                abort(403, 'You are not authorized to access this module.');
            }

            return redirect(Dashboard::getUrl());
        }

        if ($path === 'admin' || $path === 'admin/') {
            return redirect(OrderResource::getUrl());
        }

        $allowedPrefixes = [
            'admin/orders',
            'admin/logout',
            'livewire',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            abort(403, 'You are not authorized to access this module.');
        }

        return redirect(OrderResource::getUrl());
    }
}
