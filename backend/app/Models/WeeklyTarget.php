<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WeeklyTarget extends Model
{
    public const STATUS_LABELS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ];

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    private const COLLECTION_ACHIEVEMENT_STATUS = Collection::STATUS_RECEIVED;

    protected $fillable = [
        'employee_id',
        'monthly_target_id',
        'week_start_date',
        'week_end_date',
        'sales_target',
        'collection_target',
        'field_activity_target',
        'status',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'week_start_date' => 'date',
            'week_end_date' => 'date',
            'sales_target' => 'decimal:2',
            'collection_target' => 'decimal:2',
            'field_activity_target' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function monthlyTarget(): BelongsTo
    {
        return $this->belongsTo(MonthlyTarget::class);
    }

    public function isGeneratedFromMonthly(): bool
    {
        return $this->monthly_target_id !== null;
    }

    public static function businessToday(): Carbon
    {
        return Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay();
    }

    public static function updatedAtBusinessDateSql(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "date(datetime(updated_at, '+5 hours', '+30 minutes'))";
        }

        return "DATE(CONVERT_TZ(updated_at, '+00:00', '+05:30'))";
    }

    public static function activeForEmployee(int $employeeId, ?Carbon $date = null): ?self
    {
        $date ??= self::businessToday();

        return self::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->whereDate('week_start_date', '<=', $date)
            ->whereDate('week_end_date', '>=', $date)
            ->first();
    }

    public function salesAchieved(int $employeeId): float
    {
        $weekStart = $this->week_start_date->toDateString();
        $weekEnd = $this->week_end_date->toDateString();

        return (float) Order::query()
            ->where('sales_employee_id', $employeeId)
            ->where('status', Order::STATUS_DISPATCHED)
            ->where(function ($query) use ($weekStart, $weekEnd) {
                $query->whereBetween('order_date', [$weekStart, $weekEnd])
                    ->orWhereRaw(
                        self::updatedAtBusinessDateSql().' BETWEEN ? AND ?',
                        [$weekStart, $weekEnd]
                    );
            })
            ->sum('grand_total');
    }

    public function collectionAchieved(int $employeeId): float
    {
        $weekStart = $this->week_start_date->toDateString();
        $weekEnd = $this->week_end_date->toDateString();

        return (float) Collection::query()
            ->where('sales_employee_id', $employeeId)
            ->whereBetween('collection_date', [$weekStart, $weekEnd])
            ->where('status', self::COLLECTION_ACHIEVEMENT_STATUS)
            ->sum('amount');
    }

    public function fieldActivityAchieved(int $employeeId): int
    {
        $weekStart = $this->week_start_date->toDateString();
        $weekEnd = $this->week_end_date->toDateString();

        return (int) FieldActivity::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('activity_date', [$weekStart, $weekEnd])
            ->count();
    }
}
