<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Widgets\Widget;

class AdminDirectorPaymentOverviewWidget extends Widget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-payment-overview-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $data = app(DirectorDashboardDataService::class)->snapshot();
        $payments = $data['payments'];
        $today = $data['today'];
        $format = DirectorDashboardDataService::formatCompact(...);
        $canOpen = PaymentRequestResource::canAccess();

        $url = function (array $filters) use ($canOpen): ?string {
            return $canOpen ? PaymentRequestResource::getUrl('index', ['filters' => $filters]) : null;
        };

        return [
            'stats' => [
                [
                    'label' => 'Pending My Approval',
                    'value' => $payments['my_pending_count'].' Requests',
                    'hint' => $format((float) $payments['my_pending_amount']),
                    'tone' => ((int) $payments['my_pending_count'] > 0) ? 'red' : 'green',
                    'icon' => 'heroicon-o-clock',
                    'alert' => (int) $payments['my_pending_count'] > 0,
                    'url' => $url(['workflow_status' => ['value' => $payments['my_filter']]]),
                ],
                [
                    'label' => 'Pending Next Approval',
                    'value' => $payments['next_count'].' Requests',
                    'hint' => $format((float) $payments['next_amount']),
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-clipboard-document-check',
                    'alert' => false,
                    'url' => $url(['workflow_status' => ['value' => $payments['next_filter']]]),
                ],
                [
                    'label' => 'Payment Done Today',
                    'value' => $payments['paid_today_count'].' Requests',
                    'hint' => $format((float) $payments['paid_today_amount']),
                    'tone' => 'green',
                    'icon' => 'heroicon-o-banknotes',
                    'alert' => false,
                    'url' => $url([
                        'workflow_status' => ['value' => 'payment_done'],
                        'payment_done_at' => ['date' => $today],
                    ]),
                ],
            ],
        ];
    }
}
