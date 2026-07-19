<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class AdminDirectorWelcomeWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-welcome-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();

        return [
            'userName' => $user?->employee?->full_name ?? $user?->name ?? 'User',
            'roleLabel' => $user?->adminDirectorRoleLabel() ?? 'Admin',
            'currentDate' => Carbon::now('Asia/Kolkata')->format('l, d F Y'),
        ];
    }
}
