<?php

namespace App\Models;

use App\Support\IndianCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CollectionAudit extends Model
{
    public $timestamps = false;

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    /** @var list<string> */
    private const TRACKED_FIELDS = [
        'collection_date',
        'dealer_id',
        'sales_employee_id',
        'amount',
        'status',
        'remarks',
        'admin_remark',
        'receipt_no',
        'payment_mode',
        'bank_name',
        'transaction_number',
    ];

    protected $fillable = [
        'collection_id',
        'changed_by',
        'old_values',
        'new_values',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Collection $collection): array
    {
        $collection->loadMissing(['dealer:id,firm_name', 'salesEmployee:id,full_name']);

        return [
            'collection_date' => $collection->collection_date?->toDateString(),
            'dealer_id' => $collection->dealer_id,
            'dealer' => $collection->dealer?->firm_name,
            'sales_employee_id' => $collection->sales_employee_id,
            'sales_employee' => $collection->salesEmployee?->full_name,
            'amount' => round((float) $collection->amount, 2),
            'status' => $collection->status,
            'remarks' => $collection->remarks,
            'admin_remark' => $collection->admin_remark,
            'receipt_no' => $collection->receipt_no,
            'payment_mode' => $collection->payment_mode,
            'bank_name' => $collection->bank_name,
            'transaction_number' => $collection->transaction_number,
        ];
    }

    public static function record(Collection $collection, array $old, ?User $actor): ?self
    {
        $new = self::snapshot($collection->fresh() ?? $collection);
        $changed = false;

        foreach (self::TRACKED_FIELDS as $field) {
            if (self::normalize($old[$field] ?? null) !== self::normalize($new[$field] ?? null)) {
                $changed = true;
                break;
            }
        }

        if (! $changed) {
            return null;
        }

        return self::query()->create([
            'collection_id' => $collection->id,
            'changed_by' => $actor?->id,
            'old_values' => $old,
            'new_values' => $new,
            'created_at' => Carbon::now(self::BUSINESS_TIMEZONE),
        ]);
    }

    public function formattedChangedAt(): string
    {
        return Carbon::parse($this->created_at)
            ->timezone(self::BUSINESS_TIMEZONE)
            ->format('d M Y • h:i A');
    }

    /**
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    public function auditRows(): array
    {
        $old = is_array($this->old_values) ? $this->old_values : [];
        $new = is_array($this->new_values) ? $this->new_values : [];
        $rows = [];

        foreach (self::TRACKED_FIELDS as $field) {
            if (self::normalize($old[$field] ?? null) === self::normalize($new[$field] ?? null)) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'label' => self::fieldLabel($field),
                'old' => self::formatFieldValue($field, $old),
                'new' => self::formatFieldValue($field, $new),
            ];
        }

        return $rows;
    }

    private static function fieldLabel(string $field): string
    {
        return match ($field) {
            'collection_date' => 'Collection Date',
            'dealer_id' => 'Dealer',
            'sales_employee_id' => 'Sales Employee',
            'amount' => 'Amount',
            'status' => 'Status',
            'remarks' => 'Remark',
            'admin_remark' => 'Status Remark',
            'receipt_no' => 'Receipt No.',
            'payment_mode' => 'Payment Mode',
            'bank_name' => 'Bank Name',
            'transaction_number' => 'Transaction / Reference No.',
            default => $field,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function formatFieldValue(string $field, array $values): string
    {
        $value = match ($field) {
            'dealer_id' => $values['dealer'] ?? $values['dealer_id'] ?? null,
            'sales_employee_id' => $values['sales_employee'] ?? $values['sales_employee_id'] ?? null,
            'status' => Collection::STATUS_LABELS[$values['status'] ?? ''] ?? ($values['status'] ?? null),
            'amount' => isset($values['amount']) ? IndianCurrency::formatExact($values['amount']) : null,
            default => $values[$field] ?? null,
        };

        if ($value === null || $value === '') {
            return '—';
        }

        return (string) $value;
    }

    private static function normalize(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value) && ! is_string($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return trim((string) $value);
    }
}
