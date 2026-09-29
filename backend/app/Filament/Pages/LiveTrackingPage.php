<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Services\LiveTrackingService;
use App\Support\LiveTracking;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class LiveTrackingPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Employee Management';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Live Tracking';

    protected static ?string $title = 'Live Tracking';

    protected static ?string $slug = 'live-tracking';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected string $view = 'filament.pages.live-tracking';

    protected Width|string|null $maxContentWidth = Width::Full;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(LiveTrackingService::class)->canView($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    public function liveSnapshot(): array
    {
        abort_unless(static::canAccess(), 403);

        $snapshot = app(LiveTrackingService::class)->snapshot(auth()->user());
        $snapshot['employees'] = array_map(function (array $employee): array {
            $employee['employee_url'] = EmployeeResource::getUrl('view', ['record' => $employee['employee_id']]);

            return $employee;
        }, $snapshot['employees']);

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function liveRoute(int $employeeId, ?int $afterPointId = null): array
    {
        abort_unless(static::canAccess(), 403);

        return app(LiveTrackingService::class)->routeForEmployee(
            auth()->user(),
            $employeeId,
            $afterPointId,
        );
    }

    public function pollSeconds(): int
    {
        return LiveTracking::POLL_SECONDS;
    }
}
