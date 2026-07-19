<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class FieldActivity extends Model
{
    use SoftDeletes;

    public const STATUS_COMPLETED = 'completed';

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public const STATUS_LABELS = [
        self::STATUS_COMPLETED => 'Completed',
    ];

    protected $fillable = [
        'employee_id',
        'farmer_name',
        'village',
        'taluka',
        'activity_date',
        'activity_time',
        'photo_path',
        'latitude',
        'longitude',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public static function businessToday(): Carbon
    {
        return Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay();
    }

    public static function businessNow(): Carbon
    {
        return Carbon::now(self::BUSINESS_TIMEZONE);
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst((string) $status);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
