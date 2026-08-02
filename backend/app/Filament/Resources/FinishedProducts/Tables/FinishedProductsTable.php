<?php

namespace App\Filament\Resources\FinishedProducts\Tables;

use App\Enums\InventoryUnit;
use App\Enums\StockItemType;
use App\Filament\Pages\StockItemLedger;
use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FinishedProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('finishedProduct.finished_product_code')
                    ->label('Finished Product Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_code')
                    ->label('Product Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->state(fn (Product $record): string => (string) (
                        $record->finishedProduct?->unit
                        ?: ($record->production_unit ?: $record->uom)
                    ))
                    ->badge(),
                TextColumn::make('current_finished_stock')
                    ->label('Current Stock')
                    ->numeric(3)
                    ->sortable()
                    ->color(fn (Product $record): string => match (true) {
                        $record->isOutOfFinishedStock() => 'danger',
                        $record->isLowFinishedStock() => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('minimum_finished_stock')
                    ->label('Min Stock')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('weighted_average_cost')
                    ->label('Avg Production Cost')
                    ->money('INR')
                    ->sortable()
                    ->visible(fn (): bool => FinishedProductResource::canViewCosts()),
                TextColumn::make('current_stock_value')
                    ->label('Stock Value')
                    ->state(fn (Product $record): float => $record->current_stock_value)
                    ->money('INR')
                    ->sortable(query: function ($query, string $direction): void {
                        $query->orderByRaw(
                            '(current_finished_stock * weighted_average_cost) '.$direction
                        );
                    })
                    ->visible(fn (): bool => FinishedProductResource::canViewCosts()),
                IconColumn::make('status')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('production_unit')
                    ->label('Unit')
                    ->options(InventoryUnit::options()),
                TernaryFilter::make('status')
                    ->label('Active')
                    ->placeholder('All products')
                    ->trueLabel('Active products')
                    ->falseLabel('Inactive products'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->authorize(fn (Product $record): bool => FinishedProductResource::canEdit($record)),
                Action::make('viewLedger')
                    ->label('View Ledger')
                    ->icon('heroicon-o-book-open')
                    ->url(fn (Product $record): string => StockItemLedger::urlForItem(
                        StockItemType::FinishedProduct->value,
                        (int) $record->id,
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => FinishedProductResource::canDeleteAny()),
                ]),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->defaultSort('product_name')
            ->emptyStateHeading('No finished products yet')
            ->emptyStateDescription('Create a Finished Product Master or link an existing sales product to begin FG stock management.');
    }
}
