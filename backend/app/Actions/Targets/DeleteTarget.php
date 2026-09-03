<?php

namespace App\Actions\Targets;

use App\Models\MonthlyTarget;
use App\Models\WeeklyTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DeleteTarget
{
    public function execute(WeeklyTarget $record): void
    {
        DB::transaction(function () use ($record): void {
            if ($record->monthly_target_id !== null) {
                $monthly = MonthlyTarget::query()->find($record->monthly_target_id);

                if ($monthly !== null) {
                    $monthly->delete();

                    return;
                }
            }

            $record->delete();
        });
    }

    /**
     * @param  Collection<int, WeeklyTarget>  $records
     */
    public function executeMany(Collection $records): void
    {
        DB::transaction(function () use ($records): void {
            $monthlyIds = $records
                ->pluck('monthly_target_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($monthlyIds !== []) {
                MonthlyTarget::query()->whereIn('id', $monthlyIds)->get()->each->delete();
            }

            $standaloneIds = $records
                ->whereNull('monthly_target_id')
                ->pluck('id')
                ->filter()
                ->all();

            if ($standaloneIds !== []) {
                WeeklyTarget::query()->whereIn('id', $standaloneIds)->delete();
            }
        });
    }
}
