<?php

namespace App\Http\Controllers\Api\Production;

use App\Enums\CompanyTransportExpenseType;
use App\Enums\CompanyTransportPaymentMode;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\CompanyTransportLedgerEntry;
use App\Services\Orders\CompanyTransportLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyTransportLedgerApiController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly CompanyTransportLedgerService $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyTransportLedgerEntry::class);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'vehicle_no' => ['nullable', 'string', 'max:50'],
            'order_no' => ['nullable', 'string', 'max:50'],
            'expense_type' => ['nullable', Rule::in(array_column(CompanyTransportExpenseType::cases(), 'value'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->ok('Company transport ledger.', $this->ledger->presentLedger(
            filters: [
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'vehicle_no' => $validated['vehicle_no'] ?? null,
                'order_no' => $validated['order_no'] ?? null,
                'expense_type' => $validated['expense_type'] ?? null,
            ],
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 50),
        ));
    }

    public function show(CompanyTransportLedgerEntry $entry): JsonResponse
    {
        $this->authorize('view', $entry);
        $entry->loadMissing(['enteredBy:id,name', 'updatedBy:id,name', 'audits.actor:id,name']);

        return $this->ok('Company transport entry.', $this->ledger->presentEntry($entry, includeAudits: true));
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $this->authorize('create', CompanyTransportLedgerEntry::class);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'transaction_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_type' => ['required', Rule::in(array_column(CompanyTransportExpenseType::cases(), 'value'))],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'vehicle_no' => ['nullable', 'string', 'max:50'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'paid_to' => ['required', 'string', 'max:255'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'order_no' => ['nullable', 'string', 'max:50'],
            'expense_other_description' => ['nullable', 'string', 'max:255', 'required_if:expense_type,other'],
            'payment_mode' => ['required', Rule::in(array_column(CompanyTransportPaymentMode::cases(), 'value'))],
            'remark' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:10240'],
            'attachment_path' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = $this->ledger->recordExpense($request->user(), $validated);

        return $this->ok('Transport expense recorded.', $this->ledger->presentEntry($entry), 201);
    }

    public function storeAttachment(Request $request): JsonResponse
    {
        $this->authorize('create', CompanyTransportLedgerEntry::class);

        $validated = $request->validate([
            'attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:10240'],
        ]);

        $path = $this->ledger->storeAttachment($validated['attachment']);

        return $this->ok('Attachment uploaded.', [
            'attachment_path' => $path,
            'url' => \App\Support\PublicMediaUrl::fromPublicPath($path),
        ]);
    }

    public function searchOrders(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyTransportLedgerEntry::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'order_date' => ['nullable', 'date'],
        ]);

        return $this->ok('Related dispatched orders.', $this->ledger->searchRelatedOrders(
            search: $validated['search'] ?? null,
            orderDate: $validated['order_date'] ?? null,
        ));
    }
}
