<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Director Total Sales drill-down from dealer ledger DEBIT entries (view-only).
 */
class DirectorLedgerSalesController extends Controller
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $range = $this->resolveRange($request);
        $payload = $this->metrics->dealerLedgerDebitSales($range['start'], $range['end']);

        return response()->json([
            'period' => $range['label'],
            ...$payload,
        ]);
    }

    public function show(Request $request, int $dealer): JsonResponse
    {
        $range = $this->resolveRange($request);
        $payload = $this->metrics->dealerLedgerDebitEntries($dealer, $range['start'], $range['end']);

        return response()->json([
            'period' => $range['label'],
            ...$payload,
        ]);
    }

    /**
     * @return array{start: \Illuminate\Support\Carbon, end: \Illuminate\Support\Carbon, label: string}
     */
    private function resolveRange(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', DashboardMetricsService::periodValidationRule()],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $start = $validated['from'] ?? $validated['start_date'] ?? null;
        $end = $validated['to'] ?? $validated['end_date'] ?? null;
        $period = $validated['period'] ?? null;

        if ($period === null && ($start !== null || $end !== null)) {
            $period = 'custom';
        }

        return $this->metrics->resolveDateRange(
            $period ?? 'year',
            $start,
            $end ?? $start,
        );
    }
}
