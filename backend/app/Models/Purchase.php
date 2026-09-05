<?php

namespace App\Models;

use App\Enums\PurchaseMaterialType;
use App\Enums\PurchaseStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    /** @var array<string, bool> */
    private array $memoizedFlags = [];

    protected $attributes = [
        'status' => 'draft',
        'total_quantity' => 0,
        'total_taxable_amount' => 0,
        'total_gst' => 0,
        'grand_total' => 0,
        'total_items' => 0,
    ];

    protected $fillable = [
        'purchase_number',
        'purchase_date',
        'supplier_id',
        'supplier_name',
        'supplier_invoice_number',
        'supplier_invoice_date',
        'material_type',
        'remarks',
        'invoice_path',
        'status',
        'total_quantity',
        'total_taxable_amount',
        'total_gst',
        'grand_total',
        'total_items',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'supplier_invoice_date' => 'date',
            'material_type' => PurchaseMaterialType::class,
            'status' => PurchaseStatus::class,
            'total_quantity' => 'decimal:3',
            'total_taxable_amount' => 'decimal:2',
            'total_gst' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function displaySupplierName(): string
    {
        return $this->supplier?->supplier_name
            ?? $this->supplier_name
            ?? '—';
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseStatus::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this->status === PurchaseStatus::Confirmed;
    }

    public function isCancelled(): bool
    {
        return $this->status === PurchaseStatus::Cancelled;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable() || $this->canSafelyEditConfirmed();
    }

    public function canSafelyEditConfirmed(): bool
    {
        return $this->status === PurchaseStatus::Confirmed
            && ! $this->hasSubsequentStockTransactions();
    }

    public function hasSubsequentStockTransactions(): bool
    {
        if (array_key_exists('hasSubsequentStockTransactions', $this->memoizedFlags)) {
            return $this->memoizedFlags['hasSubsequentStockTransactions'];
        }

        return $this->memoizedFlags['hasSubsequentStockTransactions'] = $this->resolveHasSubsequentStockTransactions();
    }

    private function resolveHasSubsequentStockTransactions(): bool
    {
        $this->loadMissing('items');

        foreach ($this->items as $item) {
            $qty = (float) $item->quantity;
            if ($qty <= 0) {
                continue;
            }

            if ($item->raw_material_id) {
                $material = RawMaterial::query()->find($item->raw_material_id);
                if ($material === null || (float) $material->current_stock + 0.0001 < $qty) {
                    return true;
                }

                $maxLedgerId = StockLedger::query()
                    ->where('item_type', StockItemType::RawMaterial)
                    ->where('raw_material_id', $item->raw_material_id)
                    ->where('reference_type', self::class)
                    ->where('reference_id', $this->id)
                    ->where('transaction_type', StockTransactionType::Purchase)
                    ->max('id');
            } else {
                $material = PackagingMaterial::query()->find($item->packaging_material_id);
                if ($material === null || (float) $material->current_stock + 0.0001 < $qty) {
                    return true;
                }

                $maxLedgerId = StockLedger::query()
                    ->where('item_type', StockItemType::PackagingMaterial)
                    ->where('packaging_material_id', $item->packaging_material_id)
                    ->where('reference_type', self::class)
                    ->where('reference_id', $this->id)
                    ->where('transaction_type', StockTransactionType::Purchase)
                    ->max('id');
            }

            if ($maxLedgerId === null) {
                return true;
            }

            $itemType = $item->raw_material_id ? StockItemType::RawMaterial : StockItemType::PackagingMaterial;
            $hasLater = StockLedger::query()
                ->where('item_type', $itemType)
                ->when(
                    $item->raw_material_id,
                    fn ($q) => $q->where('raw_material_id', $item->raw_material_id),
                    fn ($q) => $q->where('packaging_material_id', $item->packaging_material_id),
                )
                ->where('id', '>', $maxLedgerId)
                ->exists();

            if ($hasLater) {
                return true;
            }
        }

        return false;
    }
}
