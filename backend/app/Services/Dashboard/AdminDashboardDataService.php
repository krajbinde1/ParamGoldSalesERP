<?php

namespace App\Services\Dashboard;

use App\Models\Attendance;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerVisit;
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

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function recentActivity(): array
    {
        return [
            'orders' => Order::query()
                ->with(['dealer:id,firm_name', 'salesEmployee:id,full_name'])
                ->latest('order_date')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => $order->id,
                    'title' => $order->order_no,
                    'employee' => $order->salesEmployee?->full_name,
                    'subtitle' => $order->dealer?->firm_name,
                    'date' => $order->order_date?->format('d M Y'),
                    'status' => $order->status,
                    'status_label' => $order->displayStatusLabel(),
                    'status_color' => Order::statusColor($order->status),
                ])
                ->all(),
            'collections' => Collection::query()
                ->with(['dealer:id,firm_name', 'salesEmployee:id,full_name'])
                ->latest('collection_date')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Collection $collection): array => [
                    'id' => $collection->id,
                    'title' => 'COL-'.str_pad((string) $collection->id, 6, '0', STR_PAD_LEFT),
                    'employee' => $collection->salesEmployee?->full_name,
                    'subtitle' => $collection->dealer?->firm_name,
                    'date' => $collection->collection_date?->format('d M Y'),
                    'status' => $collection->status,
                    'status_label' => Collection::statusLabels()[$collection->status] ?? $collection->status,
                    'status_color' => Collection::statusColor($collection->status),
                ])
                ->all(),
            'dealer_visits' => DealerVisit::query()
                ->with(['dealer:id,firm_name', 'employee:id,full_name'])
                ->latest('visit_date')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (DealerVisit $visit): array => [
                    'id' => $visit->id,
                    'title' => $visit->dealer?->firm_name ?? 'Dealer Visit',
                    'employee' => $visit->employee?->full_name,
                    'subtitle' => $visit->visit_purpose,
                    'date' => $visit->visit_date?->format('d M Y'),
                    'status' => $visit->visit_status,
                    'status_label' => DealerVisit::statusLabel($visit->visit_status),
                    'status_color' => 'info',
                ])
                ->all(),
            'attendance' => Attendance::query()
                ->with(['employee:id,full_name'])
                ->latest('attendance_date')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Attendance $attendance): array => [
                    'id' => $attendance->id,
                    'title' => $attendance->employee?->full_name ?? 'Attendance',
                    'employee' => $attendance->employee?->full_name,
                    'subtitle' => $attendance->attendance_status,
                    'date' => $attendance->attendance_date?->format('d M Y'),
                    'status' => strtolower($attendance->attendance_status ?? 'absent'),
                    'status_label' => $attendance->attendance_status ?? 'Absent',
                    'status_color' => strtolower($attendance->attendance_status ?? '') === 'present' ? 'success' : 'warning',
                ])
                ->all(),
            'ta_da_claims' => TaDaClaim::query()
                ->with(['employee:id,full_name'])
                ->latest('claim_date')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (TaDaClaim $claim): array => [
                    'id' => $claim->id,
                    'title' => 'Claim #'.$claim->id,
                    'employee' => $claim->employee?->full_name,
                    'subtitle' => '₹'.number_format((float) $claim->total_amount, 2),
                    'date' => $claim->claim_date?->format('d M Y'),
                    'status' => $claim->status,
                    'status_label' => TaDaClaim::STATUS_LABELS[$claim->status] ?? $claim->status,
                    'status_color' => match ($claim->status) {
                        TaDaClaim::STATUS_PENDING => 'warning',
                        TaDaClaim::STATUS_APPROVED => 'success',
                        TaDaClaim::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    },
                ])
                ->all(),
        ];
    }
}
