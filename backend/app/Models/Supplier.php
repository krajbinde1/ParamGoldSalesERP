<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $attributes = [
        'status' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (Supplier $supplier): void {
            if (filled($supplier->supplier_code)) {
                return;
            }

            $prefix = 'SUP';
            $lastCode = static::query()
                ->where('supplier_code', 'like', $prefix.'%')
                ->orderByDesc('supplier_code')
                ->value('supplier_code');

            $nextNumber = $lastCode === null
                ? 1
                : ((int) substr($lastCode, strlen($prefix))) + 1;

            $supplier->supplier_code = $prefix.str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);
        });
    }

    protected $fillable = [
        'supplier_code',
        'supplier_name',
        'contact_person',
        'phone',
        'email',
        'gstin',
        'address',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inwards(): HasMany
    {
        return $this->hasMany(RawMaterialInward::class);
    }
}
