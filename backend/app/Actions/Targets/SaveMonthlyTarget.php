<?php

namespace App\Actions\Targets;

use App\Models\MonthlyTarget;
use App\Models\WeeklyTarget;
use App\Services\Targets\MonthlyTargetWeekSplitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveMonthlyTarget
{
    public function __construct(
        private readonly MonthlyTargetWeekSplitter $splitter = new MonthlyTargetWeekSplitter,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, ?MonthlyTarget $existing = null): MonthlyTarget
    {
        $monthStart = $this->splitter->startOfMonth($data['month_start_date'] ?? now(MonthlyTargetWeekSplitter::BUSINESS_TIMEZONE));
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        $payload = Validator::make(
            [
                'employee_id' => $data['employee_id'] ?? null,
                'month_start_date' => $monthStart->toDateString(),
                'sales_target' => $data['sales_target'] ?? 0,
                'collection_target' => $data['collection_target'] ?? 0,
                'field_activity_target' => $data['field_activity_target'] ?? 0,
                'status' => $data['status'] ?? 'active',
                'remark' => $data['remark'] ?? null,
            ],
            [
                'employee_id' => ['required', 'integer', 'exists:employees,id'],
                'month_start_date' => ['required', 'date'],
                'sales_target' => ['required', 'numeric', 'min:0'],
                'collection_target' => ['required', 'numeric', 'min:0'],
                'field_activity_target' => ['required', 'integer', 'min:0'],
                'status' => ['required', Rule::in(array_keys(MonthlyTarget::STATUS_LABELS))],
                'remark' => ['nullable', 'string', 'max:2000'],
            ],
        )->validate();

        $duplicate = MonthlyTarget::query()
            ->where('employee_id', $payload['employee_id'])
            ->whereDate('month_start_date', $payload['month_start_date'])
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'month_start_date' => ['A monthly target already exists for this employee and month.'],
            ]);
        }

        $this->assertNoOverlappingWeeklyTargets(
            employeeId: (int) $payload['employee_id'],
            monthStart: $monthStart->toDateString(),
            monthEnd: $monthEnd->toDateString(),
            monthlyTargetId: $existing?->id,
        );

        $weeks = $this->splitter->weeksForMonth($monthStart);
        $days = array_column($weeks, 'days');
        $salesShares = $this->splitter->allocateMoney((float) $payload['sales_target'], $days);
        $collectionShares = $this->splitter->allocateMoney((float) $payload['collection_target'], $days);
        $fieldShares = $this->splitter->allocateUnits((int) $payload['field_activity_target'], $days);

        return DB::transaction(function () use ($payload, $existing, $weeks, $salesShares, $collectionShares, $fieldShares): MonthlyTarget {
            $monthly = $existing ?? new MonthlyTarget;
            $monthly->fill($payload);
            $monthly->save();

            $keptIds = [];

            foreach ($weeks as $index => $week) {
                $start = $week['start']->toDateString();

                $weekly = WeeklyTarget::query()
                    ->where('employee_id', $payload['employee_id'])
                    ->whereDate('week_start_date', $start)
                    ->first();

                if ($weekly === null) {
                    $weekly = new WeeklyTarget([
                        'employee_id' => $payload['employee_id'],
                        'week_start_date' => $start,
                    ]);
                }

                $weekly->fill([
                    'monthly_target_id' => $monthly->id,
                    'week_end_date' => $week['end']->toDateString(),
                    'sales_target' => $salesShares[$index],
                    'collection_target' => $collectionShares[$index],
                    'field_activity_target' => $fieldShares[$index],
                    'status' => $payload['status'],
                    'remark' => $payload['remark'],
                ]);
                $weekly->save();

                $keptIds[] = $weekly->id;
            }

            $monthly->weeklyTargets()
                ->whereNotIn('id', $keptIds)
                ->delete();

            return $monthly->fresh(['weeklyTargets']) ?? $monthly;
        });
    }

    private function assertNoOverlappingWeeklyTargets(
        int $employeeId,
        string $monthStart,
        string $monthEnd,
        ?int $monthlyTargetId,
    ): void {
        $overlapping = WeeklyTarget::query()
            ->where('employee_id', $employeeId)
            ->whereDate('week_start_date', '<=', $monthEnd)
            ->whereDate('week_end_date', '>=', $monthStart)
            ->when(
                $monthlyTargetId !== null,
                fn ($query) => $query->where(function ($query) use ($monthlyTargetId): void {
                    $query->whereNull('monthly_target_id')
                        ->orWhere('monthly_target_id', '!=', $monthlyTargetId);
                }),
            )
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages([
                'month_start_date' => ['This employee already has a target overlapping this month. Remove or edit that target first.'],
            ]);
        }
    }
}
