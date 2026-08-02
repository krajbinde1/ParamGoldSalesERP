<?php

namespace App\Http\Controllers\Api\Production;

use App\Exports\Inventory\StockItemLedgerExport;
use App\Filament\Pages\StockItemLedger;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Inventory\StockItemLedgerService;
use App\Services\Inventory\StockItemLedgerResult;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Thin mobile wrapper around StockItemLedgerService (Tally-style item ledger).
 * Same service as Filament Stock Item Ledger — balances stay identical.
 */
class StockItemLedgerApiController extends Controller
{
    use RespondsWithJson;

    private const BLANK = '—';

    public function __construct(
        private readonly StockItemLedgerService $ledgerService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAccessInventoryModule(), 403);

        $validated = $this->validateLedgerFilters($request);
        $user = $request->user();
        $showCosts = $this->canViewLedgerCosts($user);

        $result = $this->ledgerService->build([
            ...$validated,
            'page' => $validated['page'] ?? 1,
            'per_page' => $validated['per_page'] ?? 200,
        ]);

        $header = $result->header;
        $totals = $result->totals;
        $rows = $result->rows;

        if (! $showCosts) {
            foreach ([
                'current_average_rate', 'opening_value', 'opening_rate', 'closing_value', 'closing_rate',
                'available_stock_value', 'current_stock_value',
            ] as $key) {
                if (array_key_exists($key, $header)) {
                    $header[$key] = null;
                }
            }
            foreach (['total_inward_value', 'total_outward_value', 'closing_rate', 'closing_value'] as $key) {
                if (array_key_exists($key, $totals)) {
                    $totals[$key] = null;
                }
            }
            $rows = array_map(static function (array $row): array {
                foreach ([
                    'rate', 'inward_rate', 'outward_rate', 'closing_rate', 'average_purchase_rate',
                    'inward_value', 'outward_value', 'opening_value', 'closing_value',
                    'average_rate_before', 'average_rate_after', 'value', 'transaction_value',
                ] as $key) {
                    if (array_key_exists($key, $row)) {
                        $row[$key] = null;
                    }
                }

                return $row;
            }, $rows);
        }

        $fromLabel = Carbon::parse((string) $header['from'])->format('d-m-Y');

        $openingBalance = $this->openingBalancePayload($header, $fromLabel, $showCosts);
        $transactions = array_map(function (array $row) use ($showCosts): array {
            return $this->transactionPayload($row, $showCosts);
        }, $rows);
        $closingBalance = $this->closingBalancePayload($totals, $showCosts);

        $displayRows = [
            $this->openingDisplayRow($openingBalance, $showCosts),
            ...array_map(fn (array $row): array => $this->transactionDisplayRow($row, $showCosts), $transactions),
            $this->closingDisplayRow($closingBalance, $showCosts),
        ];

        $item = [
            'item_type' => $header['item_type'],
            'item_type_label' => $header['item_type_label'],
            'item_id' => $header['item_id'],
            'name' => $header['item_name'],
            'code' => $header['item_code'],
            'unit' => $header['unit'],
            'category' => $header['category'] ?? null,
            'available_quantity' => $header['available_quantity'] ?? null,
            'minimum_stock' => $header['minimum_stock'] ?? null,
            'stock_status' => $header['stock_status'] ?? null,
            'current_stock_value' => $showCosts ? ($header['current_stock_value'] ?? null) : null,
            'current_average_rate' => $showCosts ? ($header['current_average_rate'] ?? null) : null,
        ];

        $filters = [
            'from' => $header['from'],
            'to' => $header['to'],
            'item_type' => $header['item_type'],
            'item_id' => $header['item_id'],
        ];

        return $this->ok('Stock item ledger loaded successfully.', [
            'item' => $item,
            'filters' => $filters,
            'opening_balance' => $openingBalance,
            'transactions' => $transactions,
            'closing_balance' => $closingBalance,
            'display_rows' => $displayRows,
            // Backward-compatible keys (same service numbers)
            'header' => $header,
            'rows' => $transactions,
            'totals' => $totals,
            'can_view_costs' => $showCosts,
            'meta' => [
                'current_page' => $result->page,
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage,
                'total' => $result->totalTransactionCount,
            ],
        ]);
    }

    /**
     * Same Excel export as Filament StockItemLedger::exportExcel().
     */
    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->canAccessInventoryModule(), 403);

        $filters = $this->filterPayload($this->validateLedgerFilters($request));

        $summary = $this->ledgerService->build([
            ...$filters,
            'page' => 1,
            'per_page' => 1,
        ], requireItem: true);

        $itemSlug = Str::slug($summary->header['item_code'] ?: $summary->header['item_name'] ?: 'item');
        $filename = sprintf(
            'Stock_Ledger_%s_%s_to_%s.xlsx',
            $itemSlug,
            $summary->header['from'],
            $summary->header['to'],
        );

        return Excel::download(
            new StockItemLedgerExport(
                filters: $filters,
                summary: $summary,
                companyName: (string) config('app.name', 'Param Gold Sales ERP'),
            ),
            $filename,
        );
    }

    /**
     * Same print blade as inventory.stock-item-ledger.print (Sanctum-authenticated).
     */
    public function print(Request $request): View|Response
    {
        abort_unless($request->user()?->canAccessInventoryModule(), 403);

        $payload = $this->ledgerViewPayload($request);

        return response()
            ->view('filament.pages.stock-item-ledger-print', $payload['view'])
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Item Stock Ledger PDF for mobile — same StockItemLedgerService data as web/print/Excel.
     */
    public function pdf(Request $request): Response
    {
        abort_unless($request->user()?->canAccessInventoryModule(), 403);

        $payload = $this->ledgerViewPayload($request);
        $viewData = $payload['view'];
        $header = $viewData['header'];
        /** @var list<array<string, mixed>> $rows */
        $rows = $viewData['rows'];
        /** @var array<string, mixed> $totals */
        $totals = $viewData['totals'];

        $transactionRows = max(0, count($rows) - 1);

        Log::info('Item stock ledger PDF generate', [
            'item_type' => $header['item_type'] ?? null,
            'item_id' => $header['item_id'] ?? null,
            'item_name' => $header['item_name'] ?? null,
            'from' => $header['from'] ?? null,
            'to' => $header['to'] ?? null,
            'opening_qty' => $header['opening_qty'] ?? null,
            'closing_qty' => $totals['closing_qty'] ?? null,
            'transaction_count' => $transactionRows,
            'stream_row_count' => count($rows),
        ]);

        $codeSlug = $this->sanitizeLedgerFilenamePart(
            (string) ($header['item_code'] ?: $header['item_name'] ?: 'item'),
        );
        $filename = sprintf(
            'Item_Stock_Ledger_%s_%s_to_%s.pdf',
            $codeSlug,
            $header['from'],
            $header['to'],
        );

        $pdf = Pdf::loadView('filament.pages.stock-item-ledger-pdf', $viewData);
        $pdf->setPaper('a4', 'landscape');

        $binary = $pdf->output();
        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            Log::error('Item stock ledger PDF generation produced invalid binary', [
                'item_id' => $header['item_id'] ?? null,
                'size' => strlen($binary),
                'header' => substr($binary, 0, 16),
            ]);
            abort(500, 'Failed to generate Item Stock Ledger PDF.');
        }

        // Explicit binary response — never Blade HTML / JSON wrapper.
        $fallback = $this->sanitizeLedgerFilenamePart(pathinfo($filename, PATHINFO_FILENAME)).'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
                'inline',
                $filename,
                $fallback,
            ),
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Shared ledger payload for print HTML and PDF — identical StockItemLedgerService data.
     *
     * @return array{
     *     summary: StockItemLedgerResult,
     *     view: array{
     *         companyName: string,
     *         header: array<string, mixed>,
     *         totals: array<string, mixed>,
     *         rows: list<array<string, mixed>>
     *     }
     * }
     */
    private function ledgerViewPayload(Request $request): array
    {
        $filters = $this->filterPayload($this->validateLedgerFilters($request));

        try {
            $summary = $this->ledgerService->build([
                ...$filters,
                'page' => 1,
                'per_page' => 1,
            ], requireItem: true);
        } catch (ValidationException $e) {
            abort(422, $e->getMessage());
        }

        $rows = iterator_to_array($this->ledgerService->streamRows($filters), false);

        return [
            'summary' => $summary,
            'view' => [
                'companyName' => (string) config('app.name', 'Param Gold Sales ERP'),
                'header' => $summary->header,
                'totals' => $summary->totals,
                'rows' => $rows,
            ],
        ];
    }

    private function sanitizeLedgerFilenamePart(string $value): string
    {
        $slug = Str::slug($value, '_');

        return $slug !== '' ? $slug : 'item';
    }

    /**
     * @return array<string, mixed>
     */
    private function validateLedgerFilters(Request $request): array
    {
        $validated = $request->validate([
            'item_type' => ['required', 'string'],
            'item_id' => ['required', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'transaction_type' => ['nullable', 'string'],
            'voucher_number' => ['nullable', 'string', 'max:255'],
            'production_batch' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if (filled($validated['from'] ?? null) && filled($validated['to'] ?? null)
            && (string) $validated['from'] > (string) $validated['to']
        ) {
            throw ValidationException::withMessages([
                'from' => 'From Date cannot be after To Date.',
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function filterPayload(array $validated): array
    {
        return [
            'item_type' => $validated['item_type'],
            'item_id' => $validated['item_id'],
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'transaction_type' => $validated['transaction_type'] ?? null,
            'voucher_number' => $validated['voucher_number'] ?? null,
            'supplier' => null,
            'production_batch' => $validated['production_batch'] ?? null,
            'inward_only' => false,
            'outward_only' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function openingBalancePayload(array $header, string $fromLabel, bool $showCosts): array
    {
        return [
            'row_type' => 'opening_balance',
            'date' => $fromLabel,
            'particulars' => 'Opening Balance',
            'voucher_reference_number' => null,
            'voucher_reference_id' => null,
            'reference_kind' => null,
            'inward_quantity' => null,
            'inward_value' => null,
            'outward_quantity' => null,
            'outward_value' => null,
            'closing_quantity' => $header['opening_qty'],
            'average_purchase_rate' => $showCosts ? $header['opening_rate'] : null,
            'closing_value' => $showCosts ? $header['opening_value'] : null,
            'inward_qty' => null,
            'outward_qty' => null,
            'closing_qty' => $header['opening_qty'],
            'closing_rate' => $showCosts ? $header['opening_rate'] : null,
            'opening_qty' => $header['opening_qty'],
            'opening_rate' => $showCosts ? $header['opening_rate'] : null,
            'opening_value' => $showCosts ? $header['opening_value'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function transactionPayload(array $row, bool $showCosts): array
    {
        $kind = $row['reference_kind'] ?? null;
        $refId = isset($row['reference_id']) ? (int) $row['reference_id'] : null;

        return [
            ...$row,
            'row_type' => 'transaction',
            'voucher_reference_number' => $row['voucher_reference_number']
                ?? (filled($row['voucher_no'] ?? null) ? $row['voucher_no'] : null),
            'voucher_reference_id' => $refId,
            'reference_kind' => $kind,
            'mobile_route' => $this->mobileRouteFor($kind, $refId),
            'inward_quantity' => $row['inward_quantity'] ?? $row['inward_qty'] ?? null,
            'outward_quantity' => $row['outward_quantity'] ?? $row['outward_qty'] ?? null,
            'closing_quantity' => $row['closing_quantity'] ?? $row['closing_qty'] ?? null,
            'average_purchase_rate' => $showCosts
                ? ($row['average_purchase_rate'] ?? $row['closing_rate'] ?? null)
                : null,
            'remark' => $row['remark'] ?? $row['remarks'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function closingBalancePayload(array $totals, bool $showCosts): array
    {
        return [
            'row_type' => 'closing_balance',
            'particulars' => 'Closing Balance',
            'total_inward_quantity' => $totals['total_inward_qty'],
            'total_inward_value' => $showCosts ? $totals['total_inward_value'] : null,
            'total_outward_quantity' => $totals['total_outward_qty'],
            'total_outward_value' => $showCosts ? $totals['total_outward_value'] : null,
            'closing_quantity' => $totals['closing_qty'],
            'average_purchase_rate' => $showCosts ? $totals['closing_rate'] : null,
            'closing_value' => $showCosts ? $totals['closing_value'] : null,
            'total_inward_qty' => $totals['total_inward_qty'],
            'total_outward_qty' => $totals['total_outward_qty'],
            'closing_qty' => $totals['closing_qty'],
            'closing_rate' => $showCosts ? $totals['closing_rate'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $opening
     * @return array<string, mixed>
     */
    private function openingDisplayRow(array $opening, bool $showCosts): array
    {
        return [
            'row_type' => 'opening_balance',
            'date' => $opening['date'],
            'particulars' => 'Opening Balance',
            'voucher_no' => null,
            'voucher_url' => null,
            'mobile_route' => null,
            'inward_qty' => null,
            'inward_value' => null,
            'outward_qty' => null,
            'outward_value' => null,
            'closing_qty' => $opening['closing_quantity'],
            'average_purchase_rate' => $opening['average_purchase_rate'],
            'closing_value' => $opening['closing_value'],
            'date_display' => (string) $opening['date'],
            'particulars_display' => 'Opening Balance',
            'voucher_display' => self::BLANK,
            'inward_qty_display' => self::BLANK,
            'inward_value_display' => $showCosts ? self::BLANK : null,
            'outward_qty_display' => self::BLANK,
            'outward_value_display' => $showCosts ? self::BLANK : null,
            'closing_qty_display' => $this->qtyDisplay($opening['closing_quantity'], blankIfNull: false),
            'average_purchase_rate_display' => $showCosts
                ? $this->rateDisplay($opening['average_purchase_rate'], blankIfNull: false)
                : null,
            'closing_value_display' => $showCosts
                ? $this->moneyDisplay($opening['closing_value'], blankIfNull: false)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function transactionDisplayRow(array $row, bool $showCosts): array
    {
        $voucher = filled($row['voucher_reference_number'] ?? null)
            ? (string) $row['voucher_reference_number']
            : (filled($row['voucher_no'] ?? null) ? (string) $row['voucher_no'] : null);

        return [
            'row_type' => 'transaction',
            'date' => $row['date'] ?? '',
            'particulars' => $row['particulars'] ?? '',
            'voucher_no' => $voucher,
            'voucher_url' => $row['voucher_url'] ?? null,
            'mobile_route' => $row['mobile_route'] ?? null,
            'reference_kind' => $row['reference_kind'] ?? null,
            'reference_id' => $row['reference_id'] ?? $row['voucher_reference_id'] ?? null,
            'inward_qty' => $row['inward_quantity'] ?? $row['inward_qty'] ?? null,
            'inward_value' => $row['inward_value'] ?? null,
            'outward_qty' => $row['outward_quantity'] ?? $row['outward_qty'] ?? null,
            'outward_value' => $row['outward_value'] ?? null,
            'closing_qty' => $row['closing_quantity'] ?? $row['closing_qty'] ?? null,
            'average_purchase_rate' => $row['average_purchase_rate'] ?? $row['closing_rate'] ?? null,
            'closing_value' => $row['closing_value'] ?? null,
            'date_display' => (string) ($row['date'] ?? ''),
            'particulars_display' => (string) ($row['particulars'] ?? ''),
            'voucher_display' => $voucher !== null && $voucher !== '' ? $voucher : self::BLANK,
            'inward_qty_display' => $this->qtyDisplay($row['inward_quantity'] ?? $row['inward_qty'] ?? null),
            'inward_value_display' => $showCosts
                ? $this->moneyDisplay($row['inward_value'] ?? null)
                : null,
            'outward_qty_display' => $this->qtyDisplay($row['outward_quantity'] ?? $row['outward_qty'] ?? null),
            'outward_value_display' => $showCosts
                ? $this->moneyDisplay($row['outward_value'] ?? null)
                : null,
            'closing_qty_display' => $this->qtyDisplay(
                $row['closing_quantity'] ?? $row['closing_qty'] ?? null,
                blankIfNull: false,
            ),
            'average_purchase_rate_display' => $showCosts
                ? $this->rateDisplay(
                    $row['average_purchase_rate'] ?? $row['closing_rate'] ?? null,
                    blankIfNull: false,
                )
                : null,
            'closing_value_display' => $showCosts
                ? $this->moneyDisplay($row['closing_value'] ?? null, blankIfNull: false)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $closing
     * @return array<string, mixed>
     */
    private function closingDisplayRow(array $closing, bool $showCosts): array
    {
        return [
            'row_type' => 'closing_balance',
            'date' => '',
            'particulars' => 'Closing Balance',
            'voucher_no' => null,
            'voucher_url' => null,
            'mobile_route' => null,
            'inward_qty' => $closing['total_inward_quantity'],
            'inward_value' => $closing['total_inward_value'],
            'outward_qty' => $closing['total_outward_quantity'],
            'outward_value' => $closing['total_outward_value'],
            'closing_qty' => $closing['closing_quantity'],
            'average_purchase_rate' => $closing['average_purchase_rate'],
            'closing_value' => $closing['closing_value'],
            'date_display' => '',
            'particulars_display' => 'Closing Balance',
            'voucher_display' => '',
            'inward_qty_display' => $this->qtyDisplay($closing['total_inward_quantity'], blankIfNull: false),
            'inward_value_display' => $showCosts
                ? $this->moneyDisplay($closing['total_inward_value'], blankIfNull: false)
                : null,
            'outward_qty_display' => $this->qtyDisplay($closing['total_outward_quantity'], blankIfNull: false),
            'outward_value_display' => $showCosts
                ? $this->moneyDisplay($closing['total_outward_value'], blankIfNull: false)
                : null,
            'closing_qty_display' => $this->qtyDisplay($closing['closing_quantity'], blankIfNull: false),
            'average_purchase_rate_display' => $showCosts
                ? $this->rateDisplay($closing['average_purchase_rate'], blankIfNull: false)
                : null,
            'closing_value_display' => $showCosts
                ? $this->moneyDisplay($closing['closing_value'], blankIfNull: false)
                : null,
        ];
    }

    private function qtyDisplay(mixed $value, bool $blankIfNull = true): string
    {
        if ($value === null || $value === '') {
            return $blankIfNull ? self::BLANK : '';
        }

        $formatted = StockItemLedger::formatQty($value);

        return $formatted === '' && $blankIfNull ? self::BLANK : $formatted;
    }

    private function moneyDisplay(mixed $value, bool $blankIfNull = true): string
    {
        if ($value === null || $value === '') {
            return $blankIfNull ? self::BLANK : '';
        }

        $formatted = StockItemLedger::formatMoney($value);

        return $formatted === '' && $blankIfNull ? self::BLANK : $formatted;
    }

    private function rateDisplay(mixed $value, bool $blankIfNull = true): string
    {
        if ($value === null || $value === '') {
            return $blankIfNull ? self::BLANK : '';
        }

        $formatted = StockItemLedger::formatRate($value);

        return $formatted === '' && $blankIfNull ? self::BLANK : $formatted;
    }

    private function mobileRouteFor(?string $kind, ?int $id): ?string
    {
        if ($kind === null || $id === null || $id < 1) {
            return null;
        }

        return match ($kind) {
            'raw_material_inward' => '/production/inwards/'.$id,
            'packaging_material_inward' => '/production/packaging-inwards/'.$id,
            'production_batch' => '/production/batches/'.$id,
            default => null,
        };
    }

    private function canViewLedgerCosts(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // Match mobile RM inventory: supervisors see valuation/rate columns on item ledger.
        return $user->canViewInwardRates()
            || $user->canViewProductionCosts()
            || $user->canActAsProductionSupervisor();
    }
}
