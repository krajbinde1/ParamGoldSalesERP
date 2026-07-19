<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class ManagerWelcomeWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.manager-welcome-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesManagerDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();

        return [
            'managerName' => $user?->employee?->full_name ?? $user?->name ?? 'Manager',
            'roleLabel' => 'Manager',
            'currentDate' => Carbon::now('Asia/Kolkata')->format('l, d F Y'),
        ];
    }
}
