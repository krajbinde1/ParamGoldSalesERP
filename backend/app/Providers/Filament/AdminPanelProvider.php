<?php

namespace App\Providers\Filament;

use App\Filament\Auth\LoginResponse;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\StockItemLedger;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Widgets\AccountWidget;
use App\Filament\Widgets\FilamentInfoWidget;
use App\Filament\Widgets\ProductionOrderStatsWidget;
use App\Http\Controllers\Api\DealerApplicationDocumentController;
use App\Http\Controllers\Api\Director\PaymentRequestSupportingDocumentController;
use App\Http\Middleware\RestrictOrdersOnlyFilamentAccess;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('ParamGold ERP')
            // Shared main content width for Dashboard, Orders, and all Admin modules.
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::Amber,
                'success' => Color::Green,
                'warning' => Color::Orange,
                'danger' => Color::Red,
                'info' => Color::Blue,
                'amber' => Color::Amber,
                'indigo' => Color::Indigo,
            ])
            ->navigationGroups([
                'Sales Operations',
                'Employee Management',
                'Inventory & Manufacturing',
                'System',
            ])
            ->navigation(fn (): bool => ! request()->routeIs('filament.admin.resources.employee-routes.view'))
            ->topbar(fn (): bool => ! request()->routeIs('filament.admin.resources.employee-routes.view'))
            ->breadcrumbs(fn (): bool => ! request()->routeIs('filament.admin.resources.employee-routes.view'))
            ->homeUrl(function (): string {
                if (auth()->user()?->usesProductionSupervisorDashboard()) {
                    return Dashboard::getUrl();
                }

                if (auth()->user()?->hasOrdersOnlyFilamentAccess()) {
                    return OrderResource::getUrl();
                }

                return Dashboard::getUrl();
            })
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->authenticatedRoutes(function (): void {
                Route::get('/inventory-reports/ledger/{itemType}/{itemId}', StockItemLedger::class)
                    ->whereNumber('itemId')
                    ->whereIn('itemType', ['raw-material', 'packaging-material', 'finished-product'])
                    ->name('inventory-reports.ledger');

                // Admin Filament View links must stay inside /admin so panel auth applies.
                Route::get(
                    '/payment-requests/{paymentRequest}/supporting-documents/{supportingDocument}',
                    [PaymentRequestSupportingDocumentController::class, 'show']
                )->name('payment-requests.supporting-documents.show');

                Route::get(
                    '/dealer-applications/{dealerApplication}/documents/{dealerApplicationDocument}',
                    [DealerApplicationDocumentController::class, 'show']
                )->name('dealer-applications.documents.show');
            })
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                ProductionOrderStatsWidget::class,
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => '<div class="paramgold-admin-shell">')
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => '</div>')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.partials.paramgold-admin-theme'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                RestrictOrdersOnlyFilamentAccess::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
    }
}
