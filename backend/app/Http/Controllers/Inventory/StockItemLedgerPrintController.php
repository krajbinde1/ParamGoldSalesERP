<?php

namespace App\Http\Controllers\Inventory;

use App\Filament\Pages\StockItemLedger;
use App\Services\Inventory\StockItemLedgerService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class StockItemLedgerPrintController
{
    public function __invoke(Request $request, StockItemLedgerService $service): View
    {
        abort_unless(StockItemLedger::canAccess(), 403);

        $filters = $request->validate([
            'item_type' => ['required', 'string'],
            'item_id' => ['required', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'transaction_type' => ['nullable', 'string'],
            'voucher_number' => ['nullable', 'string'],
            'supplier' => ['nullable', 'string'],
            'production_batch' => ['nullable', 'string'],
            'inward_only' => ['nullable'],
            'outward_only' => ['nullable'],
        ]);

        try {
            $summary = $service->build([
                ...$filters,
                'page' => 1,
                'per_page' => 1,
            ], requireItem: true);
        } catch (ValidationException $e) {
            abort(422, $e->getMessage());
        }

        $rows = iterator_to_array($service->streamRows($filters), false);

        return view('filament.pages.stock-item-ledger-print', [
            'companyName' => (string) config('app.name', 'Param Gold Sales ERP'),
            'header' => $summary->header,
            'totals' => $summary->totals,
            'rows' => $rows,
        ]);
    }
}
