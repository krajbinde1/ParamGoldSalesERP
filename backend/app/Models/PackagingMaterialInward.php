<?php

namespace App\Models;

use App\Enums\RawMaterialInwardStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackagingMaterialInward extends Model
{
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
        return $this->hasMany(PackagingMaterialInwardItem::class)->orderBy('sort_order');
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
}
