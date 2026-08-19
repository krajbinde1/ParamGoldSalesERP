<?php

namespace App\Services\Dashboard;

use App\Models\Collection;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\TaDaClaim;

class AdminDashboardDataService
{
    /**
     * @return array<string, int|float>
     */
    public function businessSummaryCounts(): array
    {
        return [
            'total_employees' => Employee::query()->count(),
            'active_employees' => Employee::query()->where('status', true)->count(),
            'total_dealers' => Dealer::query()->count(),
            'total_products' => Product::query()->count(),
            'total_orders' => Order::query()->count(),
            'pending_orders' => Order::query()->where('status', 'pending_approval')->count(),
            'approved_orders' => Order::query()->where('status', 'approved')->count(),
            'dispatched_orders' => Order::query()->where('status', Order::STATUS_DISPATCHED)->count(),
            'total_collections' => Collection::query()->count(),
            'total_collection_amount' => round((float) Collection::query()->sum('amount'), 2),
            'pending_ta_da_claims' => TaDaClaim::query()->where('status', TaDaClaim::STATUS_PENDING)->count(),
        ];
    }
}
