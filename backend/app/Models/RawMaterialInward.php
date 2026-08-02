<?php

namespace App\Models;

use App\Enums\RawMaterialInwardStatus;
use App\Enums\StockItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterialInward extends Model
{
    /** @var array<string, bool> */
    private array $memoizedFlags = [];

    protected $attributes = [
        'status' => 'draft',
        'total_basic_value' => 0,
        'total_discount' => 0,
        'total_freight' => 0,
        'total_other_charges' => 0,
        'total_taxable_value' => 0,
        'total_gst' => 0,
        'grand_total' => 0,
        'total_accepted_qty' => 0,
        'total_rejected_qty' => 0,
        'total_items' => 0,
    ];

    protected $fillable = [
        'inward_number',
        'inward_date',
        'supplier_id',
        'supplier_name',
        'supplier_invoice_number',
        'supplier_invoice_date',
        'purchase_order_number',
        'vehicle_number',
        'transporter_name',
        'challan_number',
        'received_by',
        'warehouse',
        'remarks',
        'attachment_path',
        'status',
        'total_basic_value',
        'total_discount',
        'total_freight',
        'total_other_charges',
        'total_taxable_value',
        'total_gst',
        'grand_total',
        'total_accepted_qty',
        'total_rejected_qty',
        'total_items',
        'created_by',
        'approved_by',
        'approved_at',
        'posted_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'inward_date' => 'date',
            'supplier_invoice_date' => 'date',
            'status' => RawMaterialInwardStatus::class,
            'total_basic_value' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total_freight' => 'decimal:2',
            'total_other_charges' => 'decimal:2',
            'total_taxable_value' => 'decimal:2',
            'total_gst' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'total_accepted_qty' => 'decimal:3',
            'total_rejected_qty' => 'decimal:3',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RawMaterialInwardItem::class)->orderBy('sort_order');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(RawMaterialBatch::class, 'inward_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(RawMaterialInwardReturn::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isPosted(): bool
    {
        return $this->status === RawMaterialInwardStatus::Posted
            || $this->status === RawMaterialInwardStatus::Returned;
    }

    /**
     * Posted inward with no returns, unused batches, and no later stock movements.
     */
    public function canSafelyEditPosted(): bool
    {
        return $this->status === RawMaterialInwardStatus::Posted
            && ! $this->hasSubsequentStockTransactions();
    }

    /**
     * True when edit must be blocked (dependents / later stock activity).
     */
    public function isLockedFromEdit(): bool
    {
        if ($this->isEditable()) {
            return false;
        }

        if ($this->status === RawMaterialInwardStatus::Posted) {
            return $this->hasSubsequentStockTransactions();
        }

        return true;
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
        if ($this->returns()->exists()) {
            return true;
        }

        $this->loadMissing(['items', 'batches']);

        foreach ($this->batches as $batch) {
            if ((float) $batch->consumed_quantity > 0.0001
                || (float) $batch->returned_quantity > 0.0001
                || (float) $batch->reserved_quantity > 0.0001) {
                return true;
            }

            if (abs((float) $batch->available_quantity - (float) $batch->accepted_quantity) > 0.0001) {
                return true;
            }
        }

        $materialIds = $this->items
            ->filter(fn ($item): bool => (float) $item->accepted_quantity > 0)
            ->pluck('raw_material_id')
            ->filter()
            ->unique()
            ->values();

        if ($materialIds->isEmpty()) {
            return false;
        }

        $materials = RawMaterial::query()
            ->whereIn('id', $materialIds)
            ->get(['id', 'current_stock'])
            ->keyBy('id');

        foreach ($this->items as $item) {
            $accepted = (float) $item->accepted_quantity;
            if ($accepted <= 0) {
                continue;
            }

            $material = $materials->get($item->raw_material_id);
            if ($material === null) {
                return true;
            }

            if ((float) $material->current_stock + 0.0001 < $accepted) {
                return true;
            }

            $maxLedgerId = StockLedger::query()
                ->where('item_type', StockItemType::RawMaterial)
                ->where('raw_material_id', $item->raw_material_id)
                ->where('reference_type', self::class)
                ->where('reference_id', $this->id)
                ->max('id');

            if ($maxLedgerId === null) {
                // Posted without ledger is unexpected; treat as locked.
                return true;
            }

            $hasLater = StockLedger::query()
                ->where('item_type', StockItemType::RawMaterial)
                ->where('raw_material_id', $item->raw_material_id)
                ->where('id', '>', $maxLedgerId)
                ->exists();

            if ($hasLater) {
                return true;
            }
        }

        return false;
    }
}
