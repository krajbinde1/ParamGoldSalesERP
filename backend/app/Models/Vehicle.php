<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Vehicle extends Model
{
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'vehicle_number',
        'vehicle_name',
        'vehicle_type',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle): void {
            $vehicle->vehicle_number = self::normalizeVehicleNumber((string) $vehicle->vehicle_number);

            if (blank($vehicle->vehicle_number)) {
                throw ValidationException::withMessages([
                    'vehicle_number' => ['Vehicle number is required.'],
                ]);
            }

            $duplicate = static::query()
                ->where('vehicle_number', $vehicle->vehicle_number)
                ->when($vehicle->exists, fn (Builder $q) => $q->whereKeyNot($vehicle->getKey()))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'vehicle_number' => ['This vehicle number already exists.'],
                ]);
            }
        });
    }

    public static function normalizeVehicleNumber(string $vehicleNumber): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($vehicleNumber)));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayLabel(): string
    {
        $detail = filled($this->vehicle_name)
            ? $this->vehicle_name
            : $this->vehicle_type;

        if (filled($detail)) {
            return $this->vehicle_number.' - '.$detail;
        }

        return $this->vehicle_number;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
