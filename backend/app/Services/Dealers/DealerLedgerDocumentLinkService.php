<?php

namespace App\Services\Dealers;

use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Collection;
use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\ManagerOrderAccessService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Adds navigation from a ledger row to its original ERP document.
 * Amounts on the row are copied through unchanged.
 */
final class DealerLedgerDocumentLinkService
{
    public const TRANSACTION_OPENING = 'opening_balance';

    public const TRANSACTION_SALES_INVOICE = 'sales_invoice';

    public const TRANSACTION_PAYMENT = 'payment_received';

    public const TRANSACTION_CREDIT_NOTE = 'credit_note';

    public const TRANSACTION_SALES_RETURN = 'sales_return';

    public const TRANSACTION_TALLY_IMPORT = 'tally_import';

    public const TRANSACTION_TALLY_JOURNAL = 'tally_journal';

    public const SOURCE_ORDER = 'order';

    public const SOURCE_COLLECTION = 'collection';

    public const SOURCE_CREDIT_NOTE = 'credit_note';

    public function __construct(
        private readonly ManagerOrderAccessService $managerAccess,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public function enrich(Dealer $dealer, array $entries): array
    {
        $user = auth()->user();
        $resolvedIds = $this->resolvedSourceIds($entries);
        $orders = Order::query()->whereIn('id', $resolvedIds['orders'])->get()->keyBy('id');
        $collections = Collection::withTrashed()->whereIn('id', $resolvedIds['collections'])->get()->keyBy('id');
        $creditNotes = CreditNote::withTrashed()
            ->whereIn('id', $orders->pluck('source_credit_note_id')->filter()->map(fn ($id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');

        return array_map(function (array $entry) use ($dealer, $user, $orders, $collections, $creditNotes): array {
            return $entry + $this->linkFor($dealer, $user, $entry, $orders, $collections, $creditNotes);
        }, $entries);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{orders: list<int>, collections: list<int>, by_row: array<int, int>}
     */
    private function resolvedSourceIds(array $entries): array
    {
        $orders = [];
        $collections = [];
        $byRow = [];

        foreach ($entries as $index => $entry) {
            $source = (string) ($entry['source'] ?? '');
            $sourceId = (int) ($entry['source_id'] ?? 0);
            if ($source === DealerTallyEntry::SOURCE_SALES_ORDER && $sourceId <= 0) {
                $sourceId = $this->orderIdFromErpReference($entry['erp_reference'] ?? null);
            }
            if ($sourceId <= 0) {
                continue;
            }
            $byRow[$index] = $sourceId;
            if ($source === DealerTallyEntry::SOURCE_SALES_ORDER || $source === DealerTallyEntry::SOURCE_CREDIT_NOTE_ORDER) {
                $orders[] = $sourceId;
            }
            if ($source === DealerTallyEntry::SOURCE_COLLECTION) {
                $collections[] = $sourceId;
            }
        }

        return [
            'orders' => array_values(array_unique($orders)),
            'collections' => array_values(array_unique($collections)),
            'by_row' => $byRow,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @param  \Illuminate\Support\Collection<int, Collection>  $collections
     * @param  \Illuminate\Support\Collection<int, CreditNote>  $creditNotes
     * @return array<string, mixed>
     */
    private function linkFor(
        Dealer $dealer,
        ?User $user,
        array $entry,
        $orders,
        $collections,
        $creditNotes,
    ): array {
        $blank = $this->blankLink(
            transactionType: $this->transactionType($entry),
            referenceNo: $this->referenceNo($entry),
        );
        if ((bool) ($entry['is_opening'] ?? false)) {
            return $blank;
        }

        return match ((string) ($entry['source'] ?? '')) {
            DealerTallyEntry::SOURCE_SALES_ORDER => $this->salesLink($dealer, $user, $entry, $orders),
            DealerTallyEntry::SOURCE_COLLECTION => $this->collectionLink($dealer, $user, $entry, $collections),
            DealerTallyEntry::SOURCE_CREDIT_NOTE_ORDER => $this->creditNoteLink($dealer, $user, $entry, $orders, $creditNotes),
            default => $blank,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @return array<string, mixed>
     */
    private function salesLink(Dealer $dealer, ?User $user, array $entry, $orders): array
    {
        $reference = $this->referenceNo($entry);
        $sourceId = $this->sourceIdFor($entry);
        $base = $this->blankLink(self::TRANSACTION_SALES_INVOICE, $reference, self::SOURCE_ORDER, $sourceId);
        if ($sourceId === null) {
            return $base;
        }

        $order = $orders->get($sourceId);
        if (! $order instanceof Order) {
            return $this->unavailable($base, 'This sales invoice is no longer available.');
        }
        if ((int) $order->dealer_id !== (int) $dealer->id) {
            return $this->unavailable($base, 'This sales invoice does not belong to this dealer.');
        }
        if (! $this->canOpenOrder($user, $order)) {
            return $base;
        }

        $documentUrl = null;
        if (filled($order->bill_path) && Storage::disk('public')->exists((string) $order->bill_path)) {
            $documentUrl = $order->billUrl();
        }

        return $this->openable($base, $order->id, $documentUrl, $this->panelUrl(
            fn (): string => OrderResource::getUrl('view', ['record' => $order]),
        ));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Collection>  $collections
     * @return array<string, mixed>
     */
    private function collectionLink(Dealer $dealer, ?User $user, array $entry, $collections): array
    {
        $reference = $this->referenceNo($entry);
        $sourceId = $this->sourceIdFor($entry);
        $base = $this->blankLink(self::TRANSACTION_PAYMENT, $reference, self::SOURCE_COLLECTION, $sourceId);
        if ($sourceId === null) {
            return $base;
        }

        $collection = $collections->get($sourceId);
        if (! $collection instanceof Collection || $collection->trashed()) {
            return $this->unavailable($base, 'This payment receipt is no longer available.');
        }
        if ((int) $collection->dealer_id !== (int) $dealer->id) {
            return $this->unavailable($base, 'This payment receipt does not belong to this dealer.');
        }
        if ($user === null || ! $this->canOpenCollection($user, $collection)) {
            return $base;
        }

        return $this->openable($base, $collection->id, null, $this->panelUrl(
            fn (): string => CollectionResource::getUrl('view', ['record' => $collection]),
        ));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @param  \Illuminate\Support\Collection<int, CreditNote>  $creditNotes
     * @return array<string, mixed>
     */
    private function creditNoteLink(Dealer $dealer, ?User $user, array $entry, $orders, $creditNotes): array
    {
        $reference = $this->referenceNo($entry);
        $orderId = $this->sourceIdFor($entry);
        $base = $this->blankLink(self::TRANSACTION_CREDIT_NOTE, $reference, self::SOURCE_CREDIT_NOTE, null);
        if ($orderId === null) {
            return $base;
        }

        $order = $orders->get($orderId);
        if (! $order instanceof Order) {
            return $this->unavailable($base, 'This credit note is no longer available.');
        }
        if ((int) $order->dealer_id !== (int) $dealer->id) {
            return $this->unavailable($base, 'This credit note does not belong to this dealer.');
        }

        $creditNote = $order->source_credit_note_id !== null
            ? $creditNotes->get((int) $order->source_credit_note_id)
            : null;
        if (! $creditNote instanceof CreditNote || $creditNote->trashed()) {
            return $this->unavailable($base, 'This credit note is no longer available.');
        }
        if ((int) $creditNote->dealer_id !== (int) $dealer->id) {
            return $this->unavailable($base, 'This credit note does not belong to this dealer.');
        }
        if (! $this->canOpenCreditNote($user, $creditNote)) {
            return $base;
        }

        $transactionType = $creditNote->type === CreditNote::TYPE_SALES_RETURN
            ? self::TRANSACTION_SALES_RETURN
            : self::TRANSACTION_CREDIT_NOTE;
        $base['transaction_type'] = $transactionType;
        $documentUrl = null;
        if (filled($creditNote->supporting_document_path) && Storage::disk('public')->exists((string) $creditNote->supporting_document_path)) {
            $documentUrl = $creditNote->documentUrl();
        }

        return $this->openable($base, $creditNote->id, $documentUrl, $this->panelUrl(
            fn (): string => CreditNoteResource::getUrl('view', ['record' => $creditNote]),
        ));
    }

    private function canOpenOrder(?User $user, Order $order): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdminUser() || $user->isDirectorUser() || $user->usesAdminDirectorDashboard()) {
            return true;
        }

        return Gate::forUser($user)->allows('view', $order);
    }

    private function canOpenCreditNote(?User $user, CreditNote $creditNote): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdminUser() || $user->isDirectorUser() || $user->usesAdminDirectorDashboard()) {
            return true;
        }

        return Gate::forUser($user)->allows('view', $creditNote);
    }

    private function canOpenCollection(User $user, Collection $collection): bool
    {
        if ($user->isAdminUser() || $user->isDirectorUser() || $user->usesAdminDirectorDashboard()) {
            return true;
        }

        if ($user->employee_id !== null && (int) $collection->sales_employee_id === (int) $user->employee_id) {
            return true;
        }

        if ($user->isManagerUser()) {
            return in_array((int) $collection->sales_employee_id, $this->managerAccess->directReportEmployeeIds($user), true);
        }

        return false;
    }

    private function sourceIdFor(array $entry): ?int
    {
        $sourceId = (int) ($entry['source_id'] ?? 0);
        if ($sourceId <= 0 && (string) ($entry['source'] ?? '') === DealerTallyEntry::SOURCE_SALES_ORDER) {
            $sourceId = $this->orderIdFromErpReference($entry['erp_reference'] ?? null);
        }

        return $sourceId > 0 ? $sourceId : null;
    }

    private function orderIdFromErpReference(mixed $reference): int
    {
        $value = (string) $reference;
        if (preg_match('/^ERP-SO-(\d+)$/', $value, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function transactionType(array $entry): string
    {
        if ((bool) ($entry['is_opening'] ?? false)) {
            return self::TRANSACTION_OPENING;
        }

        return match ((string) ($entry['source'] ?? '')) {
            DealerTallyEntry::SOURCE_SALES_ORDER => self::TRANSACTION_SALES_INVOICE,
            DealerTallyEntry::SOURCE_COLLECTION => self::TRANSACTION_PAYMENT,
            DealerTallyEntry::SOURCE_CREDIT_NOTE_ORDER => self::TRANSACTION_CREDIT_NOTE,
            DealerTallyEntry::SOURCE_TALLY_IMPORT => self::TRANSACTION_TALLY_IMPORT,
            DealerTallyEntry::SOURCE_TALLY_JOURNAL => self::TRANSACTION_TALLY_JOURNAL,
            default => 'ledger_entry',
        };
    }

    private function referenceNo(array $entry): ?string
    {
        $reference = $entry['voucher_no'] ?? $entry['reference'] ?? null;

        return filled($reference) ? (string) $reference : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function blankLink(string $transactionType, ?string $referenceNo, ?string $sourceType = null, ?int $sourceId = null): array
    {
        return [
            'transaction_type' => $transactionType,
            'reference_no' => $referenceNo,
            'source_type' => $sourceType,
            'document_id' => null,
            'document_url' => null,
            'web_url' => null,
            'is_clickable' => false,
            'unavailable_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    private function unavailable(array $link, string $reason): array
    {
        $link['is_clickable'] = true;
        $link['unavailable_reason'] = $reason;
        $link['document_id'] = null;
        $link['document_url'] = null;
        $link['web_url'] = null;

        return $link;
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    private function openable(array $link, int $documentId, ?string $documentUrl, ?string $webUrl): array
    {
        $link['document_id'] = $documentId;
        $link['document_url'] = $documentUrl;
        $link['web_url'] = $webUrl;
        $link['is_clickable'] = $documentUrl !== null || $webUrl !== null;
        $link['unavailable_reason'] = $link['is_clickable']
            ? null
            : 'This document cannot be opened right now.';

        if ($link['unavailable_reason'] !== null) {
            $link['is_clickable'] = true;
        }

        return $link;
    }

    private function panelUrl(callable $url): ?string
    {
        try {
            $value = $url();

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
