<?php

namespace App\Models;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockLedger extends Model
{
    protected $fillable = [
        'transaction_date',
        'transaction_type',
        'item_type',
        'raw_material_id',
        'packaging_material_id',
        'product_id',
        'semi_finished_id',
        'reference_type',
        'reference_id',
        'reference_number',
        'supplier_invoice_number',
        'batch_number',
        'quantity_in',
        'quantity_out',
        'stock_before',
        'stock_after',
        'opening_value',
        'closing_value',
        'average_rate_before',
        'average_rate_after',
        'rate',
        'old_average_rate',
        'new_average_rate',
        'transaction_value',
        'inward_value',
        'outward_value',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'transaction_type' => StockTransactionType::class,
            'item_type' => StockItemType::class,
            'quantity_in' => 'decimal:3',
            'quantity_out' => 'decimal:3',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'opening_value' => 'decimal:4',
            'closing_value' => 'decimal:4',
            'average_rate_before' => 'decimal:4',
            'average_rate_after' => 'decimal:4',
            'rate' => 'decimal:4',
            'old_average_rate' => 'decimal:4',
            'new_average_rate' => 'decimal:4',
            'transaction_value' => 'decimal:2',
            'inward_value' => 'decimal:4',
            'outward_value' => 'decimal:4',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }
}
