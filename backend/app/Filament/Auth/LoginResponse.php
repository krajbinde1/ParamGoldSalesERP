<?php

namespace App\Filament\Auth;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = Filament::auth()->user();

        if ($user?->usesProductionSupervisorDashboard()) {
            return redirect()->intended(Dashboard::getUrl());
        }

        if ($user?->hasOrdersOnlyFilamentAccess()) {
            return redirect()->intended(OrderResource::getUrl());
        }

        return redirect()->intended(Filament::getUrl());
    }
}
