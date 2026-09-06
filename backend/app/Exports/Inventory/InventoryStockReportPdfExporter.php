<?php

namespace App\Exports\Inventory;

use App\Services\Inventory\InventoryReportResult;
use App\Services\Inventory\InventoryReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Inventory Stock Report PDF download. Uses InventoryReportService figures as-is.
 */
final class InventoryStockReportPdfExporter
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function download(array $filters, bool $showCosts, string $generatedAt): Response
    {
        $report = app(InventoryReportService::class)->build($filters);

        if (! $showCosts) {
            $report = $this->withoutCostColumns($report);
        }

        $appliedFiltersLabel = $this->appliedFiltersLabel($filters, $report);
        $viewData = $this->viewData($report, $generatedAt, $appliedFiltersLabel);

        $pdf = Pdf::loadView('filament.pages.inventory-reports-export-pdf', $viewData);
        $pdf->setPaper('a4', 'landscape');
        $pdf->setOption('isPhpEnabled', true);

        $binary = $pdf->output();
        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            Log::error('Inventory Stock Report PDF generation produced invalid binary', [
                'filters' => $filters,
                'size' => strlen($binary),
                'header' => substr($binary, 0, 16),
            ]);
            abort(500, 'Failed to generate Inventory Stock Report PDF.');
        }

        $filename = $report->filenameStem.'_'.now('Asia/Kolkata')->format('Y-m-d').'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                'attachment',
                $filename,
                'Inventory_Stock_Report.pdf',
            ),
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(InventoryReportResult $report, string $generatedAt, string $appliedFiltersLabel): array
    {
        $columns = $report->columns;
        $rows = [];

        foreach ($report->exportRows() as $row) {
            $cells = [];

            foreach ($columns as $index => $column) {
                $cells[] = [
                    'value' => $this->formatCell($column, $row[$index] ?? null),
                    'align' => $column['align'] ?? 'left',
                ];
            }

            $rows[] = $cells;
        }

        return [
            'companyName' => (string) config('app.name', 'Param Gold Sales ERP'),
            'title' => $report->title,
            'generatedAt' => $generatedAt,
            'appliedFiltersLabel' => $appliedFiltersLabel,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $this->totalLines($report),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function appliedFiltersLabel(array $filters, InventoryReportResult $report): string
    {
        if (! $this->hasAppliedFilters($filters)) {
            return 'None';
        }

        return implode(' | ', $report->appliedFilterLabels) ?: 'None';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function hasAppliedFilters(array $filters): bool
    {
        $type = (string) ($filters['inventory_type'] ?? InventoryReportService::TYPE_ALL);

        if (! array_key_exists($type, InventoryReportService::inventoryTypeOptions())) {
            $type = InventoryReportService::TYPE_ALL;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $itemKey = $filters['item_key'] ?? null;
        $status = $filters['stock_status_filter'] ?? null;

        return $type !== InventoryReportService::TYPE_ALL
            || (is_string($itemKey) && $itemKey !== '')
            || $search !== ''
            || filled($status);
    }

    /**
     * @param  array{key: string, label: string, align: string, format: string, sortable: string|false}  $column
     */
    private function formatCell(array $column, mixed $value): string
    {
        return match ($column['format']) {
            'qty' => $this->formatQty($value),
            'money' => $this->formatMoney($value),
            'rate' => $this->formatRate($value),
            'integer' => $value === null || $value === ''
                ? '—'
                : number_format((int) $value),
            'badge_stock' => match ((string) $value) {
                'out_of_stock' => 'Out of Stock',
                'low_stock' => 'Low Stock',
                'in_stock' => 'In Stock',
                default => (string) ($value ?: '—'),
            },
            default => $value === null || $value === ''
                ? '—'
                : (string) $value,
        };
    }

    /**
     * @return list<array{label: string, value: float, bold: bool}>
     */
    private function totalLines(InventoryReportResult $report): array
    {
        $breakdown = $report->footerBreakdownTotals();

        if ($breakdown === null) {
            return [];
        }

        $rows = [
            ['label' => 'Raw Material Value', 'value' => (float) ($breakdown[InventoryReportService::TYPE_RAW_MATERIAL] ?? 0.0), 'bold' => false],
            ['label' => 'Packaging Material Value', 'value' => (float) ($breakdown[InventoryReportService::TYPE_PACKAGING_MATERIAL] ?? 0.0), 'bold' => false],
            ['label' => 'Semi Finished Value', 'value' => (float) ($breakdown[InventoryReportService::TYPE_SEMI_FINISHED] ?? 0.0), 'bold' => false],
            ['label' => 'Finished Product Value', 'value' => (float) ($breakdown[InventoryReportService::TYPE_FINISHED_PRODUCT] ?? 0.0), 'bold' => false],
        ];

        $grandTotal = array_sum(array_column($rows, 'value'));
        $rows[] = ['label' => 'Grand Total Stock Value', 'value' => $grandTotal, 'bold' => true];

        return $rows;
    }

    private function withoutCostColumns(InventoryReportResult $report): InventoryReportResult
    {
        $keptIndexes = [];
        $columns = [];

        foreach ($report->columns as $index => $column) {
            if (in_array($column['format'], ['money', 'rate'], true)) {
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
            summaryCards: $report->summaryCards,
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

    private function formatQty(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.') ?: '0';
    }

    private function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return '₹'.number_format((float) $value, 2);
    }

    private function formatRate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, 2);
    }
}
