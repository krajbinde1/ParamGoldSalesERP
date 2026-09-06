<?php

namespace App\Models;

use App\Enums\CompanyTransportEntryKind;
use App\Enums\CompanyTransportExpenseType;
use App\Enums\CompanyTransportLedgerSource;
use App\Enums\CompanyTransportPaymentMode;
use App\Enums\TransportChargeType;
use App\Support\PublicMediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyTransportLedgerEntry extends Model
{
    protected $fillable = [
        'transaction_date',
        'entry_kind',
        'source',
        'particulars',
        'order_id',
        'order_no',
        'transport_charge_type',
        'vehicle_id',
        'vehicle_number',
        'expense_type',
        'paid_to',
        'payment_mode',
        'amount',
        'debit_amount',
        'credit_amount',
        'remark',
        'attachment_path',
        'entered_by',
        'entered_by_role',
        'updated_by',
        'reversed_entry_id',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'entry_kind' => CompanyTransportEntryKind::class,
            'source' => CompanyTransportLedgerSource::class,
            'expense_type' => CompanyTransportExpenseType::class,
            'payment_mode' => CompanyTransportPaymentMode::class,
            'amount' => 'decimal:2',
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(CompanyTransportLedgerEntryAudit::class, 'entry_id')->orderByDesc('id');
    }

    public function isExpense(): bool
    {
        return $this->entry_kind === CompanyTransportEntryKind::Debit
            && $this->source === CompanyTransportLedgerSource::Expense;
    }

    public function isSystemCredit(): bool
    {
        return $this->entry_kind === CompanyTransportEntryKind::Credit;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function signedAmount(): float
    {
        return round((float) $this->credit_amount - (float) $this->debit_amount, 2);
    }

    public function attachmentUrl(): ?string
    {
        return PublicMediaUrl::fromPublicPath($this->attachment_path);
    }

    public function transportTypeLabel(): ?string
    {
        return TransportChargeType::tryNormalize(
            filled($this->transport_charge_type) ? (string) $this->transport_charge_type : null,
        )?->label();
    }

    public function expenseTypeLabel(): ?string
    {
        return $this->expense_type?->label();
    }

    public function paymentModeLabel(): ?string
    {
        return $this->payment_mode?->label();
    }

    public function referenceNo(): ?string
    {
        return filled($this->order_no) ? (string) $this->order_no : null;
    }
}
