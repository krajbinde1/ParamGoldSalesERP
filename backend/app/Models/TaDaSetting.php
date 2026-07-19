<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class TaDaSetting extends Model
{
    protected $fillable = [
        'per_km_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'per_km_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TaDaSetting $setting): void {
            if ($setting->is_active) {
                static::query()->update(['is_active' => false]);
            }
        });
    }

    public static function activePerKmRate(): float
    {
        $rate = static::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->value('per_km_rate');

        if ($rate === null) {
            throw ValidationException::withMessages([
                'per_km_rate' => ['TA/DA per KM rate is not configured. Contact admin.'],
            ]);
        }

        return (float) $rate;
    }

    public static function resolvePerKmRate(Employee $employee): float
    {
        $globalRate = static::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->value('per_km_rate');

        if ($globalRate !== null) {
            return (float) $globalRate;
        }

        if (
            $employee->travel_allowance_type === 'per_km'
            && filled($employee->rate_per_km)
        ) {
            return (float) $employee->rate_per_km;
        }

        throw ValidationException::withMessages([
            'per_km_rate' => ['TA/DA per KM rate is not configured. Contact admin.'],
        ]);
    }
}
