<?php

namespace App\Filament\Resources\Targets\Pages;

use App\Actions\Targets\SaveMonthlyTarget;
use App\Filament\Resources\Targets\WeeklyTargetResource;
use App\Models\MonthlyTarget;
use App\Models\WeeklyTarget;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWeeklyTarget extends CreateRecord
{
    protected static string $resource = WeeklyTargetResource::class;

    private bool $createdMonthly = false;

    protected function getRedirectUrl(): string
    {
        if ($this->createdMonthly) {
            return WeeklyTargetResource::getUrl('index');
        }

        return parent::getRedirectUrl();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->createdMonthly
            ? 'Monthly target saved and split into weekly targets'
            : parent::getCreatedNotificationTitle();
    }

    protected function handleRecordCreation(array $data): Model
    {
        if (($data['target_type'] ?? MonthlyTarget::WEEKLY_TYPE) === MonthlyTarget::TYPE) {
            $this->createdMonthly = true;
            $monthly = app(SaveMonthlyTarget::class)->execute($data);

            $firstWeek = $monthly->weeklyTargets()->orderBy('week_start_date')->first();
            if ($firstWeek === null) {
                throw new \RuntimeException('Monthly target was saved without weekly splits.');
            }

            return $firstWeek;
        }

        unset($data['target_type'], $data['month_start_date']);

        return WeeklyTarget::query()->create($data);
    }
}
