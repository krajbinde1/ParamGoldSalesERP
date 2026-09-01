<?php

namespace App\Filament\Resources\SemiFinishedMaterials\Tables;

use App\Enums\InventoryUnit;
use App\Enums\StockItemType;
use App\Filament\Pages\StockItemLedger;
use App\Filament\Resources\SemiFinishedMaterials\SemiFinishedMaterialResource;
use App\Filament\Support\MaterialMasterStockColumns;
use App\Models\SemiFinishedMaterial;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SemiFinishedMaterialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('material_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('material_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit')
                    ->badge(),
                MaterialMasterStockColumns::availableStock(
                    'current_stock',
                    fn (SemiFinishedMaterial $record): string => match (true) {
                        $record->isOutOfStock() => 'danger',
                        $record->isLowStock() => 'warning',
                        default => 'success',
                    },
                ),
                TextColumn::make('average_production_cost')
                    ->label('Avg Production Cost')
                    ->money('INR')
                    ->sortable()
                    ->visible(fn (): bool => SemiFinishedMaterialResource::canViewCosts()),
                MaterialMasterStockColumns::stockValue(
                    'current_stock_value',
                    fn (): bool => SemiFinishedMaterialResource::canViewCosts(),
                ),
                TextColumn::make('minimum_stock')
                    ->label('Min Stock')
                    ->numeric(3)
                    ->sortable(),
                IconColumn::make('status')
                    ->label('Status')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('unit')
                    ->options(InventoryUnit::options()),
                TernaryFilter::make('status')
                    ->label('Status')
                    ->placeholder('All materials')
                    ->trueLabel('Active materials')
                    ->falseLabel('Inactive materials'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->authorize(fn (SemiFinishedMaterial $record): bool => SemiFinishedMaterialResource::canEdit($record)),
                Action::make('viewLedger')
                    ->label('View Ledger')
                    ->icon('heroicon-o-book-open')
                    ->url(fn (SemiFinishedMaterial $record): string => StockItemLedger::urlForItem(
                        StockItemType::SemiFinished->value,
                        (int) $record->id,
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \App\Filament\Actions\SafeDeleteActions::deleteBulkAction()
                        ->authorize(fn (): bool => SemiFinishedMaterialResource::canDeleteAny()),
                ]),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->defaultSort('material_name');
    }
}
