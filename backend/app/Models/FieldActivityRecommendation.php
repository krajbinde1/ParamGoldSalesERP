<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldActivityRecommendation extends Model
{
    protected $fillable = [
        'field_activity_id',
        'crop_id',
        'product_id',
        'dosage',
        'remark',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function fieldActivity(): BelongsTo
    {
        return $this->belongsTo(FieldActivity::class);
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'crop_id' => $this->crop_id,
            'crop_name' => $this->crop?->name,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->product_name,
            'product_code' => $this->product?->product_code,
            'dosage' => $this->dosage,
            'remark' => $this->remark,
        ];
    }
}
