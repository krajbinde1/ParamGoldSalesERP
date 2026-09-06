<?php

namespace App\Exports\Inventory;

use App\Models\ProductionBatch;
use App\Services\Inventory\ProductionBatchSheetPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Production Batch Sheet PDF. Same blade + presenter as Admin "Print Batch Sheet".
 */
final class ProductionBatchSheetPdfExporter
{
    public function download(ProductionBatch $batch, bool $showCosts): Response
    {
        $sheet = ProductionBatchSheetPresenter::for($batch, $showCosts);

        $pdf = Pdf::loadView('filament.resources.production-batches.production-batch-sheet-print', [
            'companyName' => (string) config('app.name', 'ParamGold ERP'),
            'sheet' => $sheet,
            'showCosts' => $showCosts,
            'forPdf' => true,
        ]);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isPhpEnabled', true);

        $binary = $pdf->output();
        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            Log::error('Production Batch Sheet PDF generation produced invalid binary', [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'size' => strlen($binary),
                'header' => substr($binary, 0, 16),
            ]);
            abort(500, 'Failed to generate Production Batch Sheet PDF.');
        }

        $asciiName = 'Production_Batch_Sheet_'.$batch->id.'.pdf';
        $filename = 'Production_Batch_Sheet_'.$batch->batch_number.'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                'attachment',
                $filename,
                $asciiName,
            ),
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
