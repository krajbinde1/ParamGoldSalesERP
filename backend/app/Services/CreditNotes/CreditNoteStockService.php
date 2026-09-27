<?php

namespace App\Services\CreditNotes;

use App\Enums\StockTransactionType;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreditNoteStockService
{
    public function __construct(
        private readonly \App\Services\Inventory\StockLedgerService $ledger = new \App\Services\Inventory\StockLedgerService,
    ) {}

    public function hasPosted(CreditNote $creditNote): bool
    {
        if ($creditNote->stock_posted_at !== null) {
            return true;
        }

        return StockLedger::query()
            ->where('reference_type', CreditNote::class)
            ->where('reference_id', $creditNote->id)
            ->where('transaction_type', StockTransactionType::Return)
            ->exists();
    }

    public function postFactoryReturn(CreditNote $creditNote, User $actor): void
    {
        if (! $creditNote->isMoveToFactory()) {
            return;
        }

        DB::transaction(function () use ($creditNote, $actor): void {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()->whereKey($creditNote->id)->lockForUpdate()->firstOrFail();

            if ($this->hasPosted($locked)) {
                return;
            }

            $locked->loadMissing('items.product');
            $date = $locked->credit_note_date?->toDateString()
                ?? now('Asia/Kolkata')->toDateString();

            foreach ($locked->items as $item) {
                $this->postItem($locked, $item, $date, $actor);
            }

            $locked->update([
                'stock_posted_at' => now('Asia/Kolkata'),
            ]);
        });
    }

    private function postItem(CreditNote $creditNote, CreditNoteItem $item, string $date, User $actor): void
    {
        $already = StockLedger::query()
            ->where('reference_type', CreditNote::class)
            ->where('reference_id', $creditNote->id)
            ->where('product_id', $item->product_id)
            ->where('transaction_type', StockTransactionType::Return)
            ->exists();

        if ($already) {
            return;
        }

        $quantity = round((float) ($item->total_quantity_nos ?? $item->quantity ?? 0), 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'items' => ['Returned quantity must be greater than zero.'],
            ]);
        }

        /** @var Product $product */
        $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
        $rate = round((float) ($product->weighted_average_cost ?: $item->rate_per_no ?: $item->rate ?: 0), 4);

        $this->ledger->postFinishedProductMovement(
            $product,
            $quantity,
            0,
            $rate,
            [
                'transaction_date' => $date,
                'transaction_type' => StockTransactionType::Return,
                'reference_type' => CreditNote::class,
                'reference_id' => $creditNote->id,
                'reference_number' => $creditNote->credit_note_no,
                'remarks' => 'Sales return to factory '.$creditNote->credit_note_no,
            ],
            $actor,
        );
    }
}
