<?php

namespace App\Filament\Resources\StockAdjustments\Tables;

use App\Enums\StockAdjustmentType;
use App\Enums\StockItemType;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockAdjustmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('adjustment_number')
                    ->label('Adjustment No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('adjustment_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('adjustment_type')
                    ->badge()
                    ->formatStateUsing(fn (StockAdjustmentType $state): string => $state->label()),
                TextColumn::make('item_type')
                    ->label('Item Type')
                    ->badge()
                    ->formatStateUsing(fn (StockItemType $state): string => $state->label()),
                TextColumn::make('item_name')
                    ->label('Item')
                    ->state(fn ($record): string => match ($record->item_type) {
                        StockItemType::RawMaterial => (string) ($record->rawMaterial?->material_name ?? '-'),
                        StockItemType::PackagingMaterial => (string) ($record->packagingMaterial?->packaging_name ?? '-'),
                        StockItemType::SemiFinished => (string) ($record->semiFinished?->material_name ?? '-'),
                        StockItemType::FinishedProduct => (string) ($record->product?->product_name ?? '-'),
                    }),
                TextColumn::make('adjusted_quantity')->label('Adjusted Qty')->numeric(3),
                TextColumn::make('stock_after')->label('Stock After')->numeric(3),
                TextColumn::make('adjustment_value')->label('Value')->money('INR'),
                TextColumn::make('approvedBy.name')->label('Approved By')->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('item_type')
                    ->label('Item Type')
                    ->options(StockItemType::options()),
                SelectFilter::make('adjustment_type')
                    ->label('Adjustment Type')
                    ->options(StockAdjustmentType::options()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->defaultSort('id', 'desc');
    }
}
