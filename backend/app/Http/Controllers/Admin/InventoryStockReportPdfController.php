<?php

namespace App\Http\Controllers\Admin;

use App\Exports\Inventory\InventoryStockReportPdfExporter;
use App\Filament\Pages\InventoryReports;
use App\Services\Inventory\InventoryReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InventoryStockReportPdfController
{
    public function __invoke(Request $request, InventoryStockReportPdfExporter $exporter): Response
    {
        abort_unless(InventoryReports::canAccess(), 403);

        $user = $request->user();
        $showCosts = $user !== null
            && ($user->canViewInwardRates() || $user->canViewProductionCosts());

        return $exporter->download(
            $this->filtersFromRequest($request),
            $showCosts,
            now('Asia/Kolkata')->format('d M Y, h:i A'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersFromRequest(Request $request): array
    {
        $inventoryType = (string) $request->query('inventory_type', InventoryReportService::TYPE_ALL);
        if (! array_key_exists($inventoryType, InventoryReportService::inventoryTypeOptions())) {
            $inventoryType = InventoryReportService::TYPE_ALL;
        }

        $itemKey = $request->query('item_key');
        $itemKey = is_string($itemKey) && $itemKey !== '' ? $itemKey : null;

        if ($itemKey === null && $request->filled('item_id') && $inventoryType !== InventoryReportService::TYPE_ALL) {
            $itemId = $request->integer('item_id');
            if ($itemId > 0) {
                $itemKey = $inventoryType.':'.$itemId;
            }
        }

        $search = $request->query('search');
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;

        $stockStatus = $request->query('stock_status');
        if (! in_array($stockStatus, ['low_stock', 'out_of_stock'], true)) {
            $stockStatus = null;
        }

        return [
            'inventory_type' => $inventoryType,
            'item_key' => $itemKey,
            'search' => $search,
            'stock_status_filter' => $stockStatus,
        ];
    }
}
