<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\TotalOutstanding;
use App\Services\Dealers\DealerOutstandingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class TotalOutstandingPdfController
{
    public function __invoke(Request $request, DealerOutstandingService $service): Response
    {
        abort_unless(TotalOutstanding::canAccess(), 403);

        $employeeId = $request->filled('employee_id') ? $request->integer('employee_id') : null;
        if ($employeeId !== null && $employeeId <= 0) {
            $employeeId = null;
        }

        $payload = $service->exportPayload($employeeId);
        $generatedAt = now('Asia/Kolkata')->format('d M Y, h:i A');
        $companyName = (string) config('app.name', 'ParamGold ERP');

        $pdf = Pdf::loadView('filament.pages.employee-outstanding-pdf', [
            'companyName' => $companyName,
            'payload' => $payload,
            'generatedAt' => $generatedAt,
        ]);
        $pdf->setPaper('a4', 'landscape');

        $binary = $pdf->output();
        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            Log::error('Total outstanding PDF generation produced invalid binary', [
                'employee_id' => $employeeId,
                'size' => strlen($binary),
                'header' => substr($binary, 0, 16),
            ]);
            abort(500, 'Failed to generate Total Outstanding PDF.');
        }

        $scope = Str::slug((string) ($payload['employee_name'] ?? 'all-employees')) ?: 'all-employees';
        $filename = sprintf(
            'Total_Outstanding_%s_%s.pdf',
            $scope,
            now('Asia/Kolkata')->format('Y-m-d'),
        );

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                'attachment',
                $filename,
                'Total_Outstanding.pdf',
            ),
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
