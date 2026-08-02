<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryReportResult;
use App\Services\Inventory\InventoryReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Thin mobile wrapper around InventoryReportService (Unified Stock Report).
 * JSON list and PDF export both use the same InventoryReportService::build() filters.
 */
class StockReportApiController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly InventoryReportService $reportService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAccessInventoryModule(), 403);

        $validated = $this->validateStockReportFilters($request);
        $showCosts = $this->canViewStockCosts($request);
        $inventoryType = $validated['inventory_type'] ?? InventoryReportService::TYPE_ALL;

        $report = $this->reportService->build([
            'inventory_type' => $inventoryType,
            'item_key' => $validated['item_key'] ?? null,
            'search' => $validated['search'] ?? null,
            'stock_status_filter' => $validated['stock_status_filter'] ?? null,
        ]);

        if (! $showCosts) {
            $report = $this->stripCostColumns($report);
        }

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginator = $report->paginate($perPage, (string) ($report->defaultSort ?? 'name'), 'asc', $page);

        $rows = collect($paginator->items())->map(function (object $record, int $index) use ($report, $paginator, $showCosts): array {
            $sr = (($paginator->currentPage() - 1) * $paginator->perPage()) + $index + 1;
            $values = ($report->rowMapper)($record, $sr);
            $mapped = [];

            foreach ($report->columns as $colIndex => $column) {
                $mapped[$column['key']] = $values[$colIndex] ?? null;
            }

            $qty = isset($record->current_stock) ? (float) $record->current_stock : null;
            $min = isset($record->minimum_stock) ? (float) $record->minimum_stock : null;
            $avgRate = isset($record->average_rate) ? (float) $record->average_rate : null;
            $stockValue = isset($record->stock_value) ? (float) $record->stock_value : null;

            $mapped['inventory_type_key'] = $record->inventory_type_key ?? null;
            $mapped['item_id'] = isset($record->item_id) ? (int) $record->item_id : null;
            $mapped['id'] = $mapped['item_id'];
            $mapped['item_key'] = isset($record->inventory_type_key, $record->item_id)
                ? $record->inventory_type_key.':'.$record->item_id
                : null;
            $mapped['name'] = $record->name ?? null;
            $mapped['code'] = $record->code ?? null;
            $mapped['unit'] = $record->unit ?? null;
            $mapped['current_stock'] = $qty;
            $mapped['available_quantity'] = $qty;
            $mapped['minimum_stock'] = $min;
            $mapped['stock_status'] = $mapped['stock_status']
                ?? $this->stockStatusKey($qty ?? 0.0, $min ?? 0.0);

            if ($showCosts) {
                $mapped['valuation_rate'] = $avgRate;
                $mapped['average_rate'] = $avgRate;
                $mapped['stock_value'] = $stockValue;
            }

            return $mapped;
        })->values()->all();

        $summaryCards = $showCosts
            ? $report->summaryCards
            : array_values(array_filter(
                $report->summaryCards,
                static fn (array $card): bool => ! str_contains((string) ($card['key'] ?? ''), 'value'),
            ));

        $filteredStockValue = $showCosts ? $report->totalStockValueFooter() : null;
        $breakdown = $showCosts ? $report->footerBreakdownTotals() : null;

        return $this->ok('Stock report loaded successfully.', [
            'summary_cards' => $summaryCards,
            'applied_filters' => $report->appliedFilterLabels,
            'inventory_type_options' => InventoryReportService::inventoryTypeOptions(),
            'columns' => $report->columns,
            'items' => $rows,
            'can_view_costs' => $showCosts,
            'summary' => [
                'filtered_stock_value' => $filteredStockValue !== null ? round((float) $filteredStockValue, 2) : null,
                'total_raw_material_value' => $this->rawMaterialTotal(
                    $inventoryType,
                    $filteredStockValue,
                    $breakdown,
                ),
                'footer_breakdown' => $breakdown,
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Inventory Stock Report PDF — same InventoryReportService filters/rows as JSON stock-report.
     */
    public function pdf(Request $request): Response
    {
        abort_unless($request->user()?->canAccessInventoryModule(), 403);

        $validated = $this->validateStockReportFilters($request, includePagination: false);
        $showCosts = $this->canViewStockCosts($request);
        $inventoryType = $validated['inventory_type'] ?? InventoryReportService::TYPE_ALL;

        $report = $this->reportService->build([
            'inventory_type' => $inventoryType,
            'item_key' => $validated['item_key'] ?? null,
            'search' => $validated['search'] ?? null,
            'stock_status_filter' => $validated['stock_status_filter'] ?? null,
        ]);

        $rows = $this->pdfRows($report);
        $filteredStockValue = $showCosts ? $report->totalStockValueFooter() : null;
        $breakdown = $showCosts ? $report->footerBreakdownTotals() : null;
        $totals = $showCosts
            ? $this->pdfTotalLines($inventoryType, $filteredStockValue, $breakdown)
            : [];

        $typeSlug = $this->sanitizeFilenamePart(
            str_replace(' ', '_', InventoryReportService::inventoryTypeOptions()[$inventoryType] ?? 'All'),
        );
        $filename = sprintf(
            'Inventory_Stock_Report_%s_%s.pdf',
            $typeSlug,
            now('Asia/Kolkata')->format('d-m-Y'),
        );

        Log::info('Inventory stock report PDF generate', [
            'inventory_type' => $inventoryType,
            'search' => $validated['search'] ?? null,
            'stock_status_filter' => $validated['stock_status_filter'] ?? null,
            'row_count' => count($rows),
            'filtered_stock_value' => $filteredStockValue,
        ]);

        $pdf = Pdf::loadView('filament.pages.inventory-stock-report-pdf', [
            'companyName' => (string) config('app.name', 'Param Gold Sales ERP'),
            'generatedAt' => now('Asia/Kolkata')->format('d M Y, h:i A'),
            'inventoryTypeLabel' => InventoryReportService::inventoryTypeOptions()[$inventoryType] ?? 'All',
            'appliedFilters' => $report->appliedFilterLabels,
            'rows' => $rows,
            'totals' => $totals,
            'showCosts' => $showCosts,
        ]);
        $pdf->setPaper('a4', 'landscape');
        $pdf->setOption('isPhpEnabled', true);

        $binary = $pdf->output();
        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            Log::error('Inventory stock report PDF generation produced invalid binary', [
                'inventory_type' => $inventoryType,
                'size' => strlen($binary),
                'header' => substr($binary, 0, 16),
            ]);
            abort(500, 'Failed to generate Inventory Stock Report PDF.');
        }

        $fallback = $this->sanitizeFilenamePart(pathinfo($filename, PATHINFO_FILENAME)).'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                'inline',
                $filename,
                $fallback,
            ),
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @return array{
     *     inventory_type?: string|null,
     *     item_key?: string|null,
     *     search?: string|null,
     *     stock_status_filter?: string|null,
     *     page?: int|null,
     *     per_page?: int|null,
     * }
     */
    private function validateStockReportFilters(Request $request, bool $includePagination = true): array
    {
        $rules = [
            'inventory_type' => ['nullable', 'string'],
            'item_key' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'stock_status_filter' => ['nullable', 'in:low_stock,out_of_stock,in_stock,available'],
        ];

        if ($includePagination) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:100'];
        }

        return $request->validate($rules);
    }

    private function canViewStockCosts(Request $request): bool
    {
        $user = $request->user();

        // Align with Raw Material / Item Ledger mobile APIs: PS can see WAVG stock values.
        return (bool) ($user?->canViewInwardRates()
            || $user?->canViewProductionCosts()
            || $user?->canActAsProductionSupervisor());
    }

    /**
     * @return list<array{
     *     sr_no: int,
     *     name: string|null,
     *     code: string|null,
     *     inventory_type: string,
     *     available_quantity: float,
     *     unit: string|null,
     *     stock_value: float,
     *     stock_status: string,
     *     stock_status_label: string
     * }>
     */
    private function pdfRows(InventoryReportResult $report): array
    {
        $query = clone $report->query;
        $sortColumn = $report->defaultSort;
        if (is_string($sortColumn) && $sortColumn !== '') {
            $query->orderBy($sortColumn, $report->defaultSortDirection);
        }

        $rows = [];
        $sr = 0;

        foreach ($query->cursor() as $record) {
            $sr++;
            $qty = (float) ($record->current_stock ?? 0);
            $min = (float) ($record->minimum_stock ?? 0);
            $status = $this->stockStatusKey($qty, $min);

            $rows[] = [
                'sr_no' => $sr,
                'name' => $record->name ?? null,
                'code' => $record->code ?? null,
                'inventory_type' => InventoryReportService::inventoryTypeLabel(
                    (string) ($record->inventory_type_key ?? ''),
                ),
                'available_quantity' => $qty,
                'unit' => $record->unit ?? null,
                'stock_value' => (float) ($record->stock_value ?? 0),
                'stock_status' => $status,
                'stock_status_label' => match ($status) {
                    'out_of_stock' => 'Out',
                    'low_stock' => 'Low',
                    default => 'In',
                },
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, float>|null  $breakdown
     * @return list<array{label: string, value: float, bold?: bool}>
     */
    private function pdfTotalLines(
        string $inventoryType,
        ?float $filteredStockValue,
        ?array $breakdown,
    ): array {
        if ($filteredStockValue === null) {
            return [];
        }

        $filtered = round((float) $filteredStockValue, 2);

        if ($inventoryType === InventoryReportService::TYPE_RAW_MATERIAL) {
            return [['label' => 'Total Raw Material Value', 'value' => $filtered, 'bold' => true]];
        }

        if ($inventoryType === InventoryReportService::TYPE_PACKAGING_MATERIAL) {
            return [['label' => 'Total Packaging Material Value', 'value' => $filtered, 'bold' => true]];
        }

        if ($inventoryType === InventoryReportService::TYPE_SEMI_FINISHED) {
            return [['label' => 'Total Semi-Finished Value', 'value' => $filtered, 'bold' => true]];
        }

        if ($inventoryType === InventoryReportService::TYPE_FINISHED_PRODUCT) {
            return [['label' => 'Total Finished Product Value', 'value' => $filtered, 'bold' => true]];
        }

        $lines = [
            [
                'label' => 'Total Raw Material Value',
                'value' => round((float) ($breakdown[InventoryReportService::TYPE_RAW_MATERIAL] ?? 0), 2),
            ],
            [
                'label' => 'Total Packaging Material Value',
                'value' => round((float) ($breakdown[InventoryReportService::TYPE_PACKAGING_MATERIAL] ?? 0), 2),
            ],
            [
                'label' => 'Total Semi-Finished Value',
                'value' => round((float) ($breakdown[InventoryReportService::TYPE_SEMI_FINISHED] ?? 0), 2),
            ],
            [
                'label' => 'Total Finished Product Value',
                'value' => round((float) ($breakdown[InventoryReportService::TYPE_FINISHED_PRODUCT] ?? 0), 2),
            ],
            [
                'label' => 'Total Inventory Value',
                'value' => $filtered,
                'bold' => true,
            ],
        ];

        return $lines;
    }

    private function sanitizeFilenamePart(string $value): string
    {
        $cleaned = preg_replace('/[^\w\-]+/u', '_', trim($value)) ?? 'report';
        $cleaned = trim(preg_replace('/_+/', '_', $cleaned) ?? 'report', '_');

        return $cleaned !== '' ? $cleaned : 'report';
    }

    /**
     * @param  array<string, float>|null  $breakdown
     */
    private function rawMaterialTotal(
        ?string $inventoryType,
        ?float $filteredStockValue,
        ?array $breakdown,
    ): ?float {
        if ($filteredStockValue === null) {
            return null;
        }

        if ($inventoryType === InventoryReportService::TYPE_RAW_MATERIAL) {
            return round((float) $filteredStockValue, 2);
        }

        if (is_array($breakdown) && array_key_exists(InventoryReportService::TYPE_RAW_MATERIAL, $breakdown)) {
            return round((float) $breakdown[InventoryReportService::TYPE_RAW_MATERIAL], 2);
        }

        return null;
    }

    private function stockStatusKey(float $qty, float $min): string
    {
        if ($qty <= 0) {
            return 'out_of_stock';
        }

        if ($qty <= $min) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    private function stripCostColumns(InventoryReportResult $report): InventoryReportResult
    {
        $keptIndexes = [];
        $columns = [];

        foreach ($report->columns as $index => $column) {
            if (in_array($column['format'] ?? '', ['money', 'rate'], true)) {
                continue;
            }
            $keptIndexes[] = $index;
            $columns[] = $column;
        }

        $originalMapper = $report->rowMapper;

        return new InventoryReportResult(
            title: $report->title,
            filenameStem: $report->filenameStem,
            columns: $columns,
            summaryCards: array_values(array_filter(
                $report->summaryCards,
                static fn (array $card): bool => ! str_contains((string) ($card['key'] ?? ''), 'value'),
            )),
            appliedFilterLabels: $report->appliedFilterLabels,
            query: $report->query,
            rowMapper: function (object $record, int $sr) use ($originalMapper, $keptIndexes): array {
                $values = $originalMapper($record, $sr);
                $filtered = [];
                foreach ($keptIndexes as $index) {
                    $filtered[] = $values[$index] ?? null;
                }

                return $filtered;
            },
            defaultSort: $report->defaultSort,
            defaultSortDirection: $report->defaultSortDirection,
            footerStockValue: null,
            footerBreakdown: null,
        );
    }
}
