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
