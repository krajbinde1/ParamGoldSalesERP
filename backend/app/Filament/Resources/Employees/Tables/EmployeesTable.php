<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Filament\Resources\Employees\Actions\ResetEmployeePasswordAction;
use App\Models\Employee;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,employee_id,role'))
            ->columns([
                ImageColumn::make('profile_photo_path')->label('Photo')->circular(),
                TextColumn::make('employee_code')->label('Employee Code')->searchable()->sortable(),
                TextColumn::make('full_name')->searchable()->sortable(),
                TextColumn::make('mobile')->searchable(),
                TextColumn::make('department')->badge()->sortable(),
                TextColumn::make('designation')->searchable()->sortable(),
                TextColumn::make('user.role')
                    ->label('Login Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => UserRole::tryFromMixed($state)->label())
                    ->color(fn (?string $state): string => match (UserRole::tryFromMixed($state)) {
                        UserRole::Employee => 'info',
                        UserRole::Manager => 'warning',
                        UserRole::ProductionSupervisor => 'purple',
                        UserRole::Director => 'success',
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->leftJoin('users', 'users.employee_id', '=', 'employees.id')
                            ->orderBy('users.role', $direction)
                            ->select('employees.*');
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $matchingRoles = collect(UserRole::cases())
                            ->filter(fn (UserRole $role): bool => str_contains(strtolower($role->label()), strtolower($search))
                                || str_contains($role->value, strtolower($search)))
                            ->map(fn (UserRole $role): string => $role->value)
                            ->values()
                            ->all();

                        if ($matchingRoles === []) {
                            return $query;
                        }

                        return $query->whereHas(
                            'user',
                            fn (Builder $userQuery): Builder => $userQuery->whereIn('role', $matchingRoles),
                        );
                    }),
                TextColumn::make('status')
                    ->label('Account Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('reportingManager.full_name')->label('Reporting Manager')->toggleable(),
                TextColumn::make('joining_date')->date()->sortable(),
                TextColumn::make('base_location')->searchable()->toggleable(),
                TextColumn::make('salary')->money('INR')->sortable(),
            ])
            ->filters([
                SelectFilter::make('login_role')
                    ->label('Login Role')
                    ->options(UserRole::options())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'user',
                            fn (Builder $userQuery): Builder => $userQuery->where('role', $value),
                        );
                    }),
                SelectFilter::make('department')
                    ->options(fn (): array => Employee::query()
                        ->whereNotNull('department')
                        ->distinct()
                        ->orderBy('department')
                        ->pluck('department', 'department')
                        ->all())
                    ->searchable(),
                SelectFilter::make('designation')
                    ->options(fn (): array => Employee::query()
                        ->whereNotNull('designation')
                        ->distinct()
                        ->orderBy('designation')
                        ->pluck('designation', 'designation')
                        ->all())
                    ->searchable(),
                TernaryFilter::make('status')
                    ->label('Account Status')
                    ->placeholder('All')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ResetEmployeePasswordAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, EloquentCollection $records): void {
                            $records->each(function (Employee $record) use ($action): void {
                                try {
                                    $deleted = app(DeleteEmployeeWithUserAccount::class)->execute($record);
                                    if (! $deleted) {
                                        $action->reportBulkProcessingFailure();
                                    }
                                } catch (\Throwable $exception) {
                                    $action->reportBulkProcessingFailure();
                                    report($exception);
                                }
                            });
                        }),
                    ForceDeleteBulkAction::make()
                        ->using(function (ForceDeleteBulkAction $action, EloquentCollection $records): void {
                            $records->each(function (Employee $record) use ($action): void {
                                try {
                                    $deleted = app(DeleteEmployeeWithUserAccount::class)->execute($record, force: true);
                                    if (! $deleted) {
                                        $action->reportBulkProcessingFailure();
                                    }
                                } catch (\Throwable $exception) {
                                    $action->reportBulkProcessingFailure();
                                    report($exception);
                                }
                            });
                        }),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
