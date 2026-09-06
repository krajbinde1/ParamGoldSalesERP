<?php

namespace App\Http\Controllers\Admin;

use App\Exports\Orders\CompanyTransportLedgerExport;
use App\Filament\Resources\CompanyTransportLedgers\CompanyTransportLedgerResource;
use App\Models\CompanyTransportLedgerEntry;
use App\Services\Orders\CompanyTransportLedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class CompanyTransportLedgerExportController
{
    public function print(Request $request, CompanyTransportLedgerService $service): Response
    {
        $this->authorizeExport();
        $filters = $this->filters($request);
        $generatedAt = now('Asia/Kolkata')->format('d M Y, h:i A');

        return response()->view('filament.resources.company-transport-ledgers.print', [
            'companyName' => (string) config('app.name', 'ParamGold ERP'),
            'entries' => $service->ledgerRows($filters),
            'summary' => $service->summary($filters),
            'liveSummary' => $service->liveSummary(),
            'filters' => $filters,
            'generatedAt' => $generatedAt,
            'autoPrint' => true,
        ]);
    }

    public function pdf(Request $request, CompanyTransportLedgerService $service): Response
    {
        $this->authorizeExport();
        $filters = $this->filters($request);
        $generatedAt = now('Asia/Kolkata')->format('d M Y, h:i A');

        $pdf = Pdf::loadView('filament.resources.company-transport-ledgers.print', [
            'companyName' => (string) config('app.name', 'ParamGold ERP'),
            'entries' => $service->ledgerRows($filters),
            'summary' => $service->summary($filters),
            'liveSummary' => $service->liveSummary(),
            'filters' => $filters,
            'generatedAt' => $generatedAt,
            'autoPrint' => false,
        ]);
        $pdf->setPaper('a4', 'landscape');

        $filename = 'Company_Transport_Ledger_'.now('Asia/Kolkata')->format('Y-m-d').'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, 'Company_Transport_Ledger.pdf'),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $this->authorizeExport();
        $filters = $this->filters($request);
        $filename = 'Company_Transport_Ledger_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx';

        return Excel::download(new CompanyTransportLedgerExport($filters), $filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'vehicle_no' => $request->query('vehicle_no'),
            'order_no' => $request->query('order_no'),
            'expense_type' => $request->query('expense_type'),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function authorizeExport(): void
    {
        abort_unless(CompanyTransportLedgerResource::canAccess(), 403);
        abort_unless(auth()->user()?->can('export', CompanyTransportLedgerEntry::class), 403);
    }
}
