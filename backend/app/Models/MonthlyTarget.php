<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class MonthlyTarget extends Model
{
    public const STATUS_LABELS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ];

    public const TYPE = 'monthly';

    public const WEEKLY_TYPE = 'weekly';

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    protected $fillable = [
        'employee_id',
        'month_start_date',
        'sales_target',
        'collection_target',
        'field_activity_target',
        'status',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'month_start_date' => 'date',
            'sales_target' => 'decimal:2',
            'collection_target' => 'decimal:2',
            'field_activity_target' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function weeklyTargets(): HasMany
    {
        return $this->hasMany(WeeklyTarget::class)->orderBy('week_start_date');
    }

    public function monthEndDate(): Carbon
    {
        return $this->month_start_date->copy()->timezone(self::BUSINESS_TIMEZONE)->endOfMonth()->startOfDay();
    }

    public function monthLabel(): string
    {
        return $this->month_start_date->timezone(self::BUSINESS_TIMEZONE)->format('F Y');
    }
}
