<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use App\Models\ProductionBatch;
use App\Services\Inventory\ProductionBatchSheetPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionBatchSheetPrintController
{
    public function __invoke(Request $request, ProductionBatch $productionBatch): View
    {
        abort_unless(ProductionBatchResource::canAccess(), 403);
        abort_unless($request->user()?->can('view', $productionBatch) ?? false, 403);

        $showCosts = $request->user()?->canViewProductionCosts() ?? false;

        return view('filament.resources.production-batches.production-batch-sheet-print', [
            'companyName' => (string) config('app.name', 'ParamGold ERP'),
            'sheet' => ProductionBatchSheetPresenter::for($productionBatch, $showCosts),
            'showCosts' => $showCosts,
        ]);
    }
}
