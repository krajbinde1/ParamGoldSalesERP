<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Widgets\AdminDirectorBusinessPerformanceWidget;
use App\Filament\Widgets\AdminDirectorEmployeePerformanceWidget;
use App\Filament\Widgets\AdminDirectorOrderOverviewWidget;
use App\Filament\Widgets\AdminDirectorPaymentOverviewWidget;
use App\Filament\Widgets\AdminDirectorWelcomeWidget;
use App\Filament\Widgets\ManagerEmployeePerformanceWidget;
use App\Filament\Widgets\ManagerOrderStatsWidget;
use App\Filament\Widgets\ManagerTeamPerformanceWidget;
use App\Filament\Widgets\ManagerWelcomeWidget;
use App\Filament\Widgets\ProductionOrderStatsWidget;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    /**
     * Use the full content area after the sidebar (no 7xl max-width).
     */
    protected Width|string|null $maxContentWidth = Width::Full;
    public static function canAccess(): bool
    {
        if (auth()->user()?->isProductionManagerOnlyInFilament()) {
            return false;
        }

        return parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (auth()->user()?->isProductionManagerOnlyInFilament()) {
            return false;
        }

        return parent::shouldRegisterNavigation();
    }

    public static function getNavigationUrl(): string
    {
        if (auth()->user()?->isProductionManagerOnlyInFilament()) {
            return OrderResource::getUrl();
        }

        return parent::getNavigationUrl();
    }

    /**
     * @return array<class-string<\Filament\Widgets\Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        if (auth()->user()?->usesProductionSupervisorDashboard()) {
            return [
                ProductionOrderStatsWidget::class,
            ];
        }

        if (auth()->user()?->usesManagerDashboard()) {
            return [
                ManagerWelcomeWidget::class,
                ManagerTeamPerformanceWidget::class,
                ManagerOrderStatsWidget::class,
                ManagerEmployeePerformanceWidget::class,
            ];
        }

        if (auth()->user()?->usesAdminDirectorDashboard()) {
            return [
                AdminDirectorWelcomeWidget::class,
                AdminDirectorBusinessPerformanceWidget::class,
                AdminDirectorOrderOverviewWidget::class,
                AdminDirectorPaymentOverviewWidget::class,
                AdminDirectorEmployeePerformanceWidget::class,
            ];
        }

        return Filament::getWidgets();
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        if (auth()->user()?->usesManagerDashboard() || auth()->user()?->usesAdminDirectorDashboard()) {
            return '';
        }

        return parent::getHeading();
    }
}
