<?php

namespace App\Models;

use App\Services\Orders\OrderLineCalculationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'product_id',
        'case_quantity',
        'nos_per_case',
        'total_quantity_nos',
        'quantity',
        'unit',
        'rate_per_no',
        'rate_type',
        'rate',
        'discount_percentage',
        'discount_amount',
        'gst_percentage',
        'base_amount',
        'taxable_amount',
        'gst_amount',
        'final_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'case_quantity' => 'integer',
            'nos_per_case' => 'integer',
            'total_quantity_nos' => 'integer',
            'quantity' => 'decimal:3',
            'rate_per_no' => 'decimal:2',
            'rate' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'gst_percentage' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OrderItem $item): void {
            if ($item->case_quantity !== null && $item->product_id !== null) {
                $product = $item->relationLoaded('product')
                    ? $item->product
                    : Product::query()->find($item->product_id);

                if ($product !== null) {
                    $submittedRate = (float) ($item->rate_per_no ?? $item->rate ?? 0);
                    $calculator = app(OrderLineCalculationService::class);
                    $rateType = $calculator->resolveRateType(
                        filled($item->rate_type) ? (string) $item->rate_type : null,
                        $submittedRate,
                        (float) $product->dealer_price,
                    );

                    $calculated = $calculator->calculateForProduct(
                        product: $product,
                        caseQuantity: (int) $item->case_quantity,
                        ratePerNo: $submittedRate,
                        requestedDiscountPercentage: (float) ($item->discount_percentage ?? 0),
                        requestedGstPercentage: (float) ($item->gst_percentage ?? 0),
                        enforceDiscountRule: false,
                        rateType: $rateType,
                    );

                    $item->fill($calculated);
                }
            }

            $amounts = app(OrderLineCalculationService::class)->resolveStoredAmounts($item);

            $item->base_amount = $amounts['base_amount'];
            $item->discount_amount = $amounts['discount_amount'];
            $item->taxable_amount = $amounts['taxable_amount'];
            $item->gst_amount = $amounts['gst_amount'];
            $item->final_amount = $amounts['final_amount'];
            $item->line_total = $amounts['final_amount'];
        });

        static::saved(fn (OrderItem $item) => $item->order?->recalculateTotals());
        static::deleted(fn (OrderItem $item) => $item->order?->recalculateTotals());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
