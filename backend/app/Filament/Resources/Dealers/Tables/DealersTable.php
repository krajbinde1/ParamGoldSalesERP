<?php

namespace App\Filament\Resources\Dealers\Tables;

use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use App\Services\Dealers\DealerLedgerService;
use App\Services\SafeDelete\SafeDeleteGuard;
use App\Support\IndianCurrency;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
                    ->label('Credit Limit')
                    ->formatStateUsing(fn ($state): string => IndianCurrency::format((float) $state))
                    ->sortable(),
                TextColumn::make('tally_ledger_status')
                    ->label('Tally Ledger Status')
                    ->badge()
                    ->state(fn (Dealer $record): string => $record->tallyLedgerImportStatusLabel())
                    ->color(fn (string $state): string => $state === 'Ledger Imported' ? 'success' : 'gray'),
                TextColumn::make('current_outstanding')
                    ->label('Outstanding')
                    ->state(function (Dealer $record): float {
                        $value = $record->getAttribute('current_outstanding');

                        if ($value !== null) {
                            return round((float) $value, 2);
                        }

                        return app(DealerLedgerService::class)->getOutstanding($record);
                    })
                    ->formatStateUsing(fn ($state): string => IndianCurrency::format((float) $state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(DealerLedgerService::currentOutstandingSql($query->getModel()->getTable()).' '.$direction);
                    })
                    ->visible(fn (): bool => auth()->user() !== null
                        && app(DealerAccessService::class)->canViewAnyLedger(auth()->user())),
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
                Filter::make('high_outstanding')
                    ->label('High outstanding')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('credit_limit', '>', 0)
                        ->whereRaw(DealerLedgerService::currentOutstandingSql($query->getModel()->getTable()).' >= credit_limit * 0.9')),
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
                Action::make('importTallyLedger')
                    ->label('Import Tally Ledger')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(fn (Dealer $record): string => DealerResource::getUrl('import-tally-ledger', ['record' => $record]))
                    ->visible(fn (Dealer $record): bool => (auth()->user()?->isAdminUser() ?? false) || (auth()->user()?->isDirectorUser() ?? false)),
                EditAction::make()
                    ->authorize(fn (Dealer $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    SafeDeleteActions::deleteBulkAction()
                        ->authorize(fn (): bool => auth()->user()?->can('deleteAny', Dealer::class) ?? false),
                    ForceDeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('forceDeleteAny', Dealer::class) ?? false)
                        ->using(function (ForceDeleteBulkAction $action, Collection $records): void {
                            $guard = app(SafeDeleteGuard::class);
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
