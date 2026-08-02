<?php

namespace App\Models;

use App\Enums\StockAdjustmentType;
use App\Enums\StockItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (StockAdjustment $adjustment): void {
            if (filled($adjustment->adjustment_number)) {
                return;
            }

            $prefix = config('inventory.stock_adjustment_prefix', 'SA');
            $datePart = now('Asia/Kolkata')->format('Ymd');
            $like = $prefix.$datePart.'%';

            $lastCode = static::query()
                ->where('adjustment_number', 'like', $like)
                ->orderByDesc('adjustment_number')
                ->value('adjustment_number');

            $nextNumber = $lastCode === null
                ? 1
                : ((int) substr((string) $lastCode, -4)) + 1;

            $adjustment->adjustment_number = $prefix.$datePart.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }

    protected $fillable = [
        'adjustment_number',
        'adjustment_date',
        'adjustment_type',
        'item_type',
        'raw_material_id',
        'packaging_material_id',
        'product_id',
        'semi_finished_id',
        'system_stock',
        'adjusted_quantity',
        'stock_after',
        'rate',
        'adjustment_value',
        'reason',
        'remarks',
        'attachment_path',
        'approved_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'adjustment_type' => StockAdjustmentType::class,
            'item_type' => StockItemType::class,
            'system_stock' => 'decimal:3',
            'adjusted_quantity' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'rate' => 'decimal:4',
            'adjustment_value' => 'decimal:2',
        ];
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function packagingMaterial(): BelongsTo
    {
        return $this->belongsTo(PackagingMaterial::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function semiFinished(): BelongsTo
    {
        return $this->belongsTo(SemiFinishedMaterial::class, 'semi_finished_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
