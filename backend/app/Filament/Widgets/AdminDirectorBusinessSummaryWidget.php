<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\Dealers\DealerResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\TaDaClaims\TaDaClaimResource;
use App\Models\Order;
use App\Models\TaDaClaim;
use App\Services\Dashboard\AdminDashboardDataService;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;

class AdminDirectorBusinessSummaryWidget extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-business-summary-widget';

    public static function canView(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $counts = app(AdminDashboardDataService::class)->businessSummaryCounts();

        return [
            'cards' => [
                ['label' => 'Total Employees', 'value' => (string) $counts['total_employees'], 'color' => 'info', 'url' => EmployeeResource::getUrl('index')],
                ['label' => 'Active Employees', 'value' => (string) $counts['active_employees'], 'color' => 'success', 'url' => EmployeeResource::getUrl('index')],
                ['label' => 'Total Dealers', 'value' => (string) $counts['total_dealers'], 'color' => 'info', 'url' => DealerResource::getUrl('index')],
                ['label' => 'Total Products', 'value' => (string) $counts['total_products'], 'color' => 'info', 'url' => ProductResource::getUrl('index')],
                ['label' => 'Total Orders', 'value' => (string) $counts['total_orders'], 'color' => 'primary', 'url' => OrderResource::getUrl('index')],
                ['label' => 'Pending Approval', 'value' => (string) $counts['pending_orders'], 'color' => 'warning', 'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => 'pending_approval']]])],
                ['label' => 'Approved Orders', 'value' => (string) $counts['approved_orders'], 'color' => 'success', 'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => 'approved']]])],
                ['label' => 'Dispatched Orders', 'value' => (string) $counts['dispatched_orders'], 'color' => 'info', 'url' => OrderResource::getUrl('index', ['filters' => ['status' => ['value' => Order::STATUS_DISPATCHED]]])],
                ['label' => 'Total Collections', 'value' => (string) $counts['total_collections'], 'color' => 'primary', 'url' => CollectionResource::getUrl('index')],
                ['label' => 'Pending TA/DA Claims', 'value' => (string) $counts['pending_ta_da_claims'], 'color' => 'warning', 'url' => TaDaClaimResource::getUrl('index', ['filters' => ['status' => ['value' => TaDaClaim::STATUS_PENDING]]])],
            ],
            'collectionAmount' => Number::currency((float) $counts['total_collection_amount'], 'INR', 'en_IN'),
        ];
    }
}
