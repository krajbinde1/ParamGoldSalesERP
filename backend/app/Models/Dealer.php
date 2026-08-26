<?php

namespace App\Models;

use App\Models\Concerns\EnforcesSafeDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Dealer extends Model
{
    use EnforcesSafeDelete;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Dealer $dealer): void {
            if (filled($dealer->dealer_code)) {
                return;
            }

            $dealer->dealer_code = static::generateNextDealerCode();
        });

        static::updating(function (Dealer $dealer): void {
            if ($dealer->isDirty('dealer_code')) {
                $dealer->dealer_code = $dealer->getOriginal('dealer_code');
            }
        });
    }

    /**
     * Generate the next short dealer code (D001, D002, … D999, D1000).
     * Existing codes (e.g. DLR000001) are left unchanged and ignored for sequencing.
     */
    public static function generateNextDealerCode(): string
    {
        return DB::transaction(function (): string {
            $maxNumber = static::withTrashed()
                ->where('dealer_code', 'like', 'D%')
                ->lockForUpdate()
                ->pluck('dealer_code')
                ->reduce(function (int $max, string $code): int {
                    if (preg_match('/^D(\d+)$/', $code, $matches) !== 1) {
                        return $max;
                    }

                    return max($max, (int) $matches[1]);
                }, 0);

            $nextNumber = $maxNumber + 1;

            return 'D'.($nextNumber < 1000
                ? str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT)
                : (string) $nextNumber);
        });
    }

    protected $fillable = [
        'dealer_code',
        'firm_name',
        'owner_name',
        'mobile',
        'email',
        'gst_no',
        'fertilizer_license_no',
        'pan_no',
        'address',
        'village',
        'taluka',
        'district',
        'state',
        'pincode',
        'latitude',
        'longitude',
        'credit_limit',
        'outstanding',
        'opening_balance',
        'opening_balance_type',
        'opening_balance_date',
        'dealer_type',
        'status',
        'assigned_employee_id',
    ];

    protected $casts = [
        'status' => 'boolean',
        'credit_limit' => 'decimal:2',
        'outstanding' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
    ];

    public const OPENING_BALANCE_DEBIT = 'debit';

    public const OPENING_BALANCE_CREDIT = 'credit';

    public function openingBalanceIsCredit(): bool
    {
        return strtolower(trim((string) ($this->opening_balance_type ?: self::OPENING_BALANCE_DEBIT)))
            === self::OPENING_BALANCE_CREDIT;
    }

    public function signedOpeningBalance(): float
    {
        $amount = round((float) ($this->opening_balance ?? 0), 2);

        return $this->openingBalanceIsCredit() ? -$amount : $amount;
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(DealerVisit::class);
    }

    public function party(): HasOne
    {
        return $this->hasOne(Party::class);
    }

    public function dealerApplication(): HasOne
    {
        return $this->hasOne(DealerApplication::class);
    }

    public function tallyLedger(): HasOne
    {
        return $this->hasOne(DealerTallyLedger::class);
    }

    public function tallyEntries(): HasMany
    {
        return $this->hasMany(DealerTallyEntry::class);
    }

    public function tallyImports(): HasMany
    {
        return $this->hasMany(DealerTallyImport::class);
    }

    public function hasImportedTallyLedger(): bool
    {
        if ($this->relationLoaded('tallyLedger')) {
            return $this->tallyLedger !== null;
        }

        return $this->tallyLedger()->exists();
    }

    public function tallyLedgerImportStatusLabel(): string
    {
        return $this->hasImportedTallyLedger() ? 'Ledger Imported' : 'Not Imported';
    }
}
