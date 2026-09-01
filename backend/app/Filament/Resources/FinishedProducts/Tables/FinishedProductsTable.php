<?php

namespace App\Filament\Resources\FinishedProducts\Tables;

use App\Enums\InventoryUnit;
use App\Enums\StockItemType;
use App\Filament\Pages\StockItemLedger;
use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use App\Filament\Support\MaterialMasterStockColumns;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
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
                TextColumn::make('product_code')
                    ->label('Product Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_name')
                    ->label('Product Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->state(fn (Product $record): string => (string) (
                        $record->production_unit
                        ?: $record->uom
                        ?: ($record->finishedProduct?->unit ?? '')
                    ))
                    ->badge(),
                MaterialMasterStockColumns::availableStock(
                    'current_finished_stock',
                    fn (Product $record): string => match (true) {
                        $record->isOutOfFinishedStock() => 'danger',
                        $record->isLowFinishedStock() => 'warning',
                        default => 'success',
                    },
                ),
                MaterialMasterStockColumns::stockValue(
                    'current_stock_value',
                    fn (): bool => FinishedProductResource::canViewCosts(),
                    fn (Product $record): float => $record->current_stock_value,
                    function ($query, string $direction): void {
                        $query->orderByRaw(
                            '(current_finished_stock * weighted_average_cost) '.$direction
                        );
                    },
                ),
                TextColumn::make('weighted_average_cost')
                    ->label('Avg / Effective Rate')
                    ->money('INR')
                    ->sortable()
                    ->visible(fn (): bool => FinishedProductResource::canViewCosts()),
                TextColumn::make('minimum_finished_stock')
                    ->label('Min Stock')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('opening_finished_stock')
                    ->label('Opening Stock')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('finishedProduct.finished_product_code')
                    ->label('FP Code')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable()
                    ->placeholder('—'),
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
                    \App\Filament\Actions\SafeDeleteActions::deleteBulkAction()
                        ->authorize(fn (): bool => FinishedProductResource::canDeleteAny()),
                ]),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->defaultSort('product_name')
            ->emptyStateHeading('No sales products yet')
            ->emptyStateDescription('Create products under Sales Operations → Products. Use Set Opening Stock or Finished Goods Opening Stock Import to post FG opening balances.');
    }
}
