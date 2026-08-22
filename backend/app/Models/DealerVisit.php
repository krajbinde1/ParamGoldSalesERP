<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class DealerVisit extends Model
{
    use SoftDeletes;

    public const STATUS_COMPLETED = 'completed';

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public const STATUS_LABELS = [
        self::STATUS_COMPLETED => 'Completed',
    ];

    protected $fillable = [
        'employee_id',
        'dealer_id',
        'is_prospective',
        'prospective_firm_name',
        'prospective_owner_name',
        'prospective_mobile',
        'prospective_village',
        'prospective_taluka',
        'prospective_district',
        'remarks',
        'visit_date',
        'visit_time',
        'photo_path',
        'latitude',
        'longitude',
        'accuracy',
        'location_captured_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'is_prospective' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'location_captured_at' => 'datetime',
        ];
    }

    public static function businessNow(): Carbon
    {
        return Carbon::now(self::BUSINESS_TIMEZONE);
    }

    public static function businessToday(): Carbon
    {
        return self::businessNow()->copy()->startOfDay();
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst((string) $status);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function displayDealerName(): string
    {
        if ($this->is_prospective) {
            return filled($this->prospective_firm_name)
                ? (string) $this->prospective_firm_name
                : 'Prospective Dealer';
        }

        return (string) ($this->dealer?->firm_name ?: '-');
    }

    public function displayOwnerName(): ?string
    {
        return $this->is_prospective
            ? $this->prospective_owner_name
            : $this->dealer?->owner_name;
    }

    public function displayVillage(): ?string
    {
        return $this->is_prospective
            ? $this->prospective_village
            : $this->dealer?->village;
    }

    public function displayTaluka(): ?string
    {
        return $this->is_prospective
            ? $this->prospective_taluka
            : $this->dealer?->taluka;
    }

    public function displayDistrict(): ?string
    {
        return $this->is_prospective
            ? $this->prospective_district
            : $this->dealer?->district;
    }

    public function photoUrl(): ?string
    {
        if (blank($this->photo_path)) {
            return null;
        }

        return url('storage/'.str_replace('\\', '/', $this->photo_path));
    }

    public function mapsUrl(): ?string
    {
        if (blank($this->latitude) || blank($this->longitude)) {
            return null;
        }

        return sprintf(
            'https://www.google.com/maps?q=%s,%s',
            $this->latitude,
            $this->longitude,
        );
    }
}
