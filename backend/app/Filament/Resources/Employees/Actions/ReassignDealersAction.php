<?php

namespace App\Filament\Resources\Employees\Actions;

use App\Actions\Employees\ReassignEmployeeDealers;
use App\Models\Employee;
use App\Support\EmployeeCodeResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class ReassignDealersAction
{
    public static function make(string $name = 'reassignDealers'): Action
    {
        return Action::make($name)
            ->label('Reassign Dealers')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->visible(fn (Employee $record): bool => $record->assignedDealers()->exists())
            ->modalHeading('Reassign Dealers')
            ->modalDescription(fn (Employee $record): string => 'This employee has '
                .$record->assignedDealers()->count()
                .' assigned dealer(s). Select another active employee to receive them.')
            ->form(fn (Employee $record): array => [
                Select::make('target_employee_id')
                    ->label('New Assigned Employee')
                    ->options(fn (): array => Employee::query()
                        ->whereKeyNot($record->getKey())
                        ->tap(fn (Builder $query) => EmployeeCodeResolver::scopeAssignableEmployees($query))
                        ->orderBy('employee_code')
                        ->get()
                        ->mapWithKeys(fn (Employee $employee): array => [
                            $employee->id => $employee->assignmentLabel(),
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Employee $record, array $data): void {
                $target = Employee::query()->findOrFail($data['target_employee_id']);
                $reassigned = app(ReassignEmployeeDealers::class)->execute($record, $target);

                Notification::make()
                    ->success()
                    ->title('Dealers reassigned')
                    ->body("{$reassigned} dealer(s) moved to {$target->assignmentLabel()}.")
                    ->send();
            });
    }
}
