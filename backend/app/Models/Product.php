<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'category' => 'General',
        'uom' => 'Piece',
        'nos_per_case' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (filled($product->product_code)) {
                return;
            }

            $lastCode = static::withTrashed()
                ->where('product_code', 'like', 'PRD%')
                ->orderByDesc('product_code')
                ->value('product_code');

            $nextNumber = $lastCode === null
                ? 1
                : ((int) substr($lastCode, 3)) + 1;

            $product->product_code = 'PRD'.str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
        });
    }

    protected $fillable = [
        'product_code',
        'product_name',
        'category',
        'brand',
        'hsn_code',
        'uom',
        'nos_per_case',
        'pack_size',
        'gst_percentage',
        'mrp',
        'distributor_price',
        'dealer_price',
        'retail_price',
        'minimum_stock',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'gst_percentage' => 'decimal:2',
            'mrp' => 'decimal:2',
            'distributor_price' => 'decimal:2',
            'dealer_price' => 'decimal:2',
            'retail_price' => 'decimal:2',
            'minimum_stock' => 'decimal:2',
            'nos_per_case' => 'integer',
            'status' => 'boolean',
        ];
    }
}
