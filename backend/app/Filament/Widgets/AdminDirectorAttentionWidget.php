<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Dealers\DealerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\Order;
use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Widgets\Widget;

class AdminDirectorAttentionWidget extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-attention-widget';

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
        $pipeline = $data['pipeline'];
        $payments = $data['payments'];
        $canOpenPayments = PaymentRequestResource::canAccess();

        $items = [
            [
                'label' => $data['not_punched_in'].' Employees Not Punched In',
                'count' => (int) $data['not_punched_in'],
                'tone' => ((int) $data['not_punched_in'] > 0) ? 'orange' : 'green',
                'url' => AttendanceResource::getUrl('index'),
                'hide_when_zero' => false,
            ],
            [
                'label' => $payments['my_pending_count'].' Payment Requests Awaiting Approval',
                'count' => (int) $payments['my_pending_count'],
                'tone' => ((int) $payments['my_pending_count'] > 0) ? 'red' : 'green',
                'url' => $canOpenPayments
                    ? PaymentRequestResource::getUrl('index', [
                        'filters' => ['workflow_status' => ['value' => $payments['my_filter']]],
                    ])
                    : null,
                'hide_when_zero' => false,
            ],
            [
                'label' => $pipeline['placed'].' Orders Pending Manager Approval',
                'count' => (int) $pipeline['placed'],
                'tone' => ((int) $pipeline['placed'] > 0) ? 'orange' : 'green',
                'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_PENDING_APPROVAL]),
                'hide_when_zero' => false,
            ],
            [
                'label' => ($pipeline['reverted_to_manager'] ?? 0).' Orders Returned by Production',
                'count' => (int) ($pipeline['reverted_to_manager'] ?? 0),
                'tone' => ((int) ($pipeline['reverted_to_manager'] ?? 0) > 0) ? 'orange' : 'green',
                'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_REVERTED_TO_MANAGER]),
                'hide_when_zero' => false,
            ],
            [
                'label' => ($pipeline['on_hold'] ?? 0).' Orders On Hold',
                'count' => (int) ($pipeline['on_hold'] ?? 0),
                'tone' => ((int) ($pipeline['on_hold'] ?? 0) > 0) ? 'orange' : 'green',
                'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_ON_HOLD]),
                'hide_when_zero' => false,
            ],
            [
                'label' => $pipeline['sent_for_bill'].' Orders Pending for Billing',
                'count' => (int) $pipeline['sent_for_bill'],
                'tone' => ((int) $pipeline['sent_for_bill'] > 0) ? 'orange' : 'green',
                'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_PENDING_FOR_BILLING]),
                'hide_when_zero' => false,
            ],
            [
                'label' => $pipeline['billed'].' Orders Waiting for Dispatch',
                'count' => (int) $pipeline['billed'],
                'tone' => ((int) $pipeline['billed'] > 0) ? 'orange' : 'green',
                'url' => OrderResource::getUrl('index', ['tab' => Order::STATUS_BILLED]),
                'hide_when_zero' => false,
            ],
            [
                'label' => $data['high_outstanding_dealers'].' Dealers with High Outstanding',
                'count' => (int) $data['high_outstanding_dealers'],
                'tone' => ((int) $data['high_outstanding_dealers'] > 0) ? 'red' : 'green',
                'url' => DealerResource::getUrl('index', [
                    'filters' => ['high_outstanding' => ['isActive' => true]],
                ]),
                'hide_when_zero' => false,
            ],
        ];

        return [
            'items' => array_values(array_filter(
                $items,
                fn (array $item): bool => ! ($item['hide_when_zero'] && $item['count'] === 0) && $item['url'] !== null,
            )),
        ];
    }
}
