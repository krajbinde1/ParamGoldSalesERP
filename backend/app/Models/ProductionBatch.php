<?php

namespace App\Models;

use App\Enums\ProductionBatchStatus;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\StockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionBatch extends Model
{
    protected $attributes = [
        'status' => 'draft',
        'output_type' => 'finished_product',
        'actual_output_quantity' => 0,
        'wastage_quantity' => 0,
        'total_material_cost' => 0,
        'total_packaging_cost' => 0,
        'labour_cost' => 0,
        'electricity_cost' => 0,
        'machine_cost' => 0,
        'processing_cost' => 0,
        'transport_cost' => 0,
        'other_manufacturing_cost' => 0,
        'total_conversion_cost' => 0,
        'total_batch_cost' => 0,
        'cost_per_unit' => 0,
        'cost_per_pack' => 0,
        'material_cost_per_unit' => 0,
        'packaging_cost_per_unit' => 0,
        'conversion_cost_per_unit' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductionBatch $batch): void {
            if (filled($batch->batch_number)) {
                return;
            }

            $prefix = config('inventory.batch_number_prefix', 'PB');
            $datePart = now('Asia/Kolkata')->format('Ymd');
            $like = $prefix.$datePart.'%';

            $lastCode = static::query()
                ->where('batch_number', 'like', $like)
                ->orderByDesc('batch_number')
                ->value('batch_number');

            $nextNumber = $lastCode === null
                ? 1
                : ((int) substr((string) $lastCode, -4)) + 1;

            $batch->batch_number = $prefix.$datePart.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }

    protected $fillable = [
        'batch_number',
        'output_type',
        'product_id',
        'semi_finished_id',
        'bom_id',
        'bom_version',
        'production_date',
        'manufacturing_date',
        'expiry_date',
        'planned_quantity',
        'actual_output_quantity',
        'finished_packs_produced',
        'wastage_quantity',
        'total_material_cost',
        'total_packaging_cost',
        'labour_cost',
        'electricity_cost',
        'machine_cost',
        'processing_cost',
        'transport_cost',
        'other_manufacturing_cost',
        'total_conversion_cost',
        'total_batch_cost',
        'cost_per_unit',
        'cost_per_pack',
        'cost_per_case',
        'material_cost_per_unit',
        'packaging_cost_per_unit',
        'conversion_cost_per_unit',
        'status',
        'supervisor_id',
        'notes',
        'has_material_deviation',
        'requires_approval',
        'has_quantity_variance',
        'submitted_for_approval_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'approval_notes',
        'started_at',
        'reversal_reason',
        'reversed_by',
        'reversed_at',
        'completed_at',
        'finished_product_posted_at',
        'finished_stock_before',
        'finished_stock_after',
        'finished_stock_value_after',
        'finished_product_ledger_id',
        'semi_finished_posted_at',
        'semi_finished_stock_before',
        'semi_finished_stock_after',
        'semi_finished_stock_value_after',
        'semi_finished_ledger_id',
        'posting_token',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'planned_quantity' => 'decimal:3',
            'actual_output_quantity' => 'decimal:3',
            'finished_packs_produced' => 'decimal:3',
            'wastage_quantity' => 'decimal:3',
            'total_material_cost' => 'decimal:2',
            'total_packaging_cost' => 'decimal:2',
            'labour_cost' => 'decimal:2',
            'electricity_cost' => 'decimal:2',
            'machine_cost' => 'decimal:2',
            'processing_cost' => 'decimal:2',
            'transport_cost' => 'decimal:2',
            'other_manufacturing_cost' => 'decimal:2',
            'total_conversion_cost' => 'decimal:2',
            'total_batch_cost' => 'decimal:2',
            'cost_per_unit' => 'decimal:4',
            'cost_per_pack' => 'decimal:4',
            'cost_per_case' => 'decimal:4',
            'material_cost_per_unit' => 'decimal:4',
            'packaging_cost_per_unit' => 'decimal:4',
            'conversion_cost_per_unit' => 'decimal:4',
            'status' => ProductionBatchStatus::class,
            'has_material_deviation' => 'boolean',
            'requires_approval' => 'boolean',
            'has_quantity_variance' => 'boolean',
            'submitted_for_approval_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'started_at' => 'datetime',
            'reversed_at' => 'datetime',
            'completed_at' => 'datetime',
            'finished_product_posted_at' => 'datetime',
            'finished_stock_before' => 'decimal:3',
            'finished_stock_after' => 'decimal:3',
            'finished_stock_value_after' => 'decimal:2',
            'semi_finished_posted_at' => 'datetime',
            'semi_finished_stock_before' => 'decimal:3',
            'semi_finished_stock_after' => 'decimal:3',
            'semi_finished_stock_value_after' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function semiFinished(): BelongsTo
    {
        return $this->belongsTo(SemiFinishedMaterial::class, 'semi_finished_id');
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionBatchConsumption::class);
    }

    public function finishedProductLedger(): BelongsTo
    {
        return $this->belongsTo(StockLedger::class, 'finished_product_ledger_id');
    }

    public function isFinishedProductStockPosted(): bool
    {
        if ($this->finished_product_posted_at !== null || $this->finished_product_ledger_id !== null) {
            return true;
        }

        if (! $this->exists) {
            return false;
        }

        return StockLedger::query()
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('transaction_type', StockTransactionType::ProductionOutput)
            ->where('reference_type', self::class)
            ->where('reference_id', $this->id)
            ->exists();
    }

    public function isCompleted(): bool
    {
        return $this->status === ProductionBatchStatus::Completed;
    }

    public function isReversed(): bool
    {
        return $this->status === ProductionBatchStatus::Reversed;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditableDraft();
    }
}
