<?php

namespace App\Filament\Resources\Targets\Pages;

use App\Actions\Targets\SaveMonthlyTarget;
use App\Filament\Resources\Targets\WeeklyTargetResource;
use App\Models\MonthlyTarget;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWeeklyTarget extends EditRecord
{
    protected static string $resource = WeeklyTargetResource::class;

    private bool $savedMonthly = false;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalHeading(fn (): string => $this->getRecord()->isGeneratedFromMonthly()
                    ? 'Delete monthly target'
                    : 'Delete target')
                ->modalDescription(fn (): ?string => $this->getRecord()->isGeneratedFromMonthly()
                    ? 'This week belongs to a monthly target. Deleting it will remove the monthly target and all of its weekly splits.'
                    : null)
                ->using(function (Model $record): void {
                    if ($record->monthlyTarget !== null) {
                        $record->monthlyTarget->delete();

                        return;
                    }

                    $record->delete();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record->isGeneratedFromMonthly() && $record->monthlyTarget !== null) {
            $monthly = $record->monthlyTarget;

            return [
                'target_type' => MonthlyTarget::TYPE,
                'employee_id' => $monthly->employee_id,
                'status' => $monthly->status,
                'month_start_date' => $monthly->month_start_date->toDateString(),
                'sales_target' => $monthly->sales_target,
                'collection_target' => $monthly->collection_target,
                'field_activity_target' => $monthly->field_activity_target,
                'remark' => $monthly->remark,
            ];
        }

        $data['target_type'] = MonthlyTarget::WEEKLY_TYPE;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        if ($this->savedMonthly) {
            return WeeklyTargetResource::getUrl('index');
        }

        return parent::getRedirectUrl();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return $this->savedMonthly
            ? 'Monthly target updated and weekly targets recalculated'
            : parent::getSavedNotificationTitle();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record->isGeneratedFromMonthly()
            || ($data['target_type'] ?? MonthlyTarget::WEEKLY_TYPE) === MonthlyTarget::TYPE) {
            $this->savedMonthly = true;
            $monthly = app(SaveMonthlyTarget::class)->execute(
                $data,
                $record->monthlyTarget,
            );

            return $monthly->weeklyTargets()->orderBy('week_start_date')->first() ?? $record;
        }

        unset($data['target_type'], $data['month_start_date']);
        $record->update($data);

        return $record;
    }
}
