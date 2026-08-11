<?php

namespace App\Filament\Resources\Dealers\Tables;

use App\Models\Dealer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DealersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('dealer_code')
                    ->searchable(),
                TextColumn::make('firm_name')
                    ->searchable(),
                TextColumn::make('owner_name')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('mobile')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gst_no')
                    ->label('GSTIN')
                    ->searchable(),
                TextColumn::make('dealer_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('assignedEmployee.employee_code')
                    ->label('Assigned Employee')
                    ->formatStateUsing(fn (Dealer $record): string => $record->assignedEmployee?->assignmentLabel() ?? '-')
                    ->sortable(),
                TextColumn::make('state')
                    ->searchable(),
                TextColumn::make('district')
                    ->searchable(),
                TextColumn::make('taluka')
                    ->searchable(),
                TextColumn::make('village')
                    ->searchable(),
                TextColumn::make('pincode')
                    ->searchable(),
                TextColumn::make('credit_limit')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('outstanding')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('latitude')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('longitude')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('status')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('dealer_type')
                    ->options([
                        'Distributor' => 'Distributor',
                        'Retailer' => 'Retailer',
                        'Wholesaler' => 'Wholesaler',
                    ]),
                TernaryFilter::make('status')
                    ->label('Status')
                    ->placeholder('All dealers')
                    ->trueLabel('Active dealers')
                    ->falseLabel('Inactive dealers'),
                SelectFilter::make('state')
                    ->options(fn (): array => Dealer::query()
                        ->whereNotNull('state')
                        ->distinct()
                        ->orderBy('state')
                        ->pluck('state', 'state')
                        ->all())
                    ->searchable(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->authorize(fn (Dealer $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \App\Filament\Actions\SafeDeleteActions::deleteBulkAction()
                        ->authorize(fn (): bool => auth()->user()?->can('deleteAny', Dealer::class) ?? false),
                    ForceDeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('forceDeleteAny', Dealer::class) ?? false)
                        ->using(function (ForceDeleteBulkAction $action, \Illuminate\Database\Eloquent\Collection $records): void {
                            $guard = app(\App\Services\SafeDelete\SafeDeleteGuard::class);
                            $records->each(function (Dealer $record) use ($action, $guard): void {
                                try {
                                    $guard->assertCanDelete($record);
                                    $record->forceDelete();
                                } catch (\Throwable $exception) {
                                    $action->reportBulkProcessingFailure();
                                    report($exception);
                                }
                            });
                        }),
                    RestoreBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('restoreAny', Dealer::class) ?? false),
                ]),
            ]);
    }
}
