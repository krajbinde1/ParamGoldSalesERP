<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\DealerVisits\DealerVisitResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

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
        $data = app(DirectorDashboardDataService::class)->snapshot($user);
        $today = $data['today'];
        $format = DirectorDashboardDataService::formatCompact(...);
        $payments = $data['payments'];
        $paymentCount = (int) $payments['my_pending_count'];
        $canOpenPayments = PaymentRequestResource::canAccess();

        return [
            'userName' => $user?->employee?->full_name ?? $user?->name ?? 'User',
            'roleLabel' => $user?->adminDirectorRoleLabel() ?? 'Director',
            'currentDate' => now('Asia/Kolkata')->format('l, d F Y'),
            'kpis' => [
                [
                    'label' => 'Today Sales',
                    'value' => $format((float) $data['today_sales']),
                    'hint' => 'Orders placed today',
                    'tone' => 'teal',
                    'icon' => 'heroicon-o-banknotes',
                    'alert' => false,
                    'url' => OrderResource::getUrl('index', [
                        'filters' => ['order_date' => ['date' => $today]],
                    ]),
                ],
                [
                    'label' => 'Today Collection',
                    'value' => $format((float) $data['today_collection']),
                    'hint' => 'Collections entered today',
                    'tone' => 'green',
                    'icon' => 'heroicon-o-wallet',
                    'alert' => false,
                    'url' => CollectionResource::getUrl('index', [
                        'filters' => ['collection_date' => ['date' => $today]],
                    ]),
                ],
                [
                    'label' => 'Team Punch In',
                    'value' => $data['punched_in'].' / '.$data['active_employees'],
                    'hint' => $data['not_punched_in'].' Not Punched In',
                    'tone' => ((int) $data['not_punched_in'] > 0) ? 'amber' : 'green',
                    'icon' => 'heroicon-o-finger-print',
                    'alert' => false,
                    'url' => AttendanceResource::getUrl('index', [
                        'filters' => ['punched_in' => ['isActive' => true]],
                    ]),
                ],
                [
                    'label' => 'Dealer Visits Today',
                    'value' => (string) $data['dealer_visits'],
                    'hint' => 'Field visits to dealers',
                    'tone' => 'blue',
                    'icon' => 'heroicon-o-building-storefront',
                    'alert' => false,
                    'url' => DealerVisitResource::getUrl('index', [
                        'filters' => ['visit_date' => ['date' => $today]],
                    ]),
                ],
                [
                    'label' => 'Pending Orders',
                    'value' => (string) $data['pending_orders'],
                    'hint' => 'Requiring action',
                    'tone' => ((int) $data['pending_orders'] > 0) ? 'amber' : 'green',
                    'icon' => 'heroicon-o-clipboard-document-list',
                    'alert' => false,
                    'url' => OrderResource::getUrl('index', [
                        'filters' => ['action_required' => ['isActive' => true]],
                    ]),
                ],
                [
                    'label' => 'Payment Approval',
                    'value' => $paymentCount.' Pending',
                    'hint' => $paymentCount > 0 ? 'Requires your action' : 'Nothing waiting',
                    'tone' => $paymentCount > 0 ? 'red' : 'green',
                    'icon' => 'heroicon-o-shield-check',
                    'alert' => $paymentCount > 0,
                    'url' => $canOpenPayments
                        ? PaymentRequestResource::getUrl('index', [
                            'filters' => ['workflow_status' => ['value' => $payments['my_filter']]],
                        ])
                        : null,
                ],
            ],
        ];
    }
}
