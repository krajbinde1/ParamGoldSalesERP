<?php

namespace App\Filament\Resources\StockLedgers\Tables;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockLedgersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('transaction_type')
                    ->badge()
                    ->formatStateUsing(fn (StockTransactionType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('item_type')
                    ->label('Item Type')
                    ->badge()
                    ->formatStateUsing(fn (StockItemType $state): string => $state->label()),
                TextColumn::make('item_name')
                    ->label('Item')
                    ->state(fn ($record): string => match ($record->item_type) {
                        StockItemType::RawMaterial => (string) ($record->rawMaterial?->material_name ?? '-'),
                        StockItemType::PackagingMaterial => (string) ($record->packagingMaterial?->packaging_name ?? '-'),
                        StockItemType::FinishedProduct => (string) ($record->product?->product_name ?? '-'),
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query
                            ->whereHas('rawMaterial', fn (Builder $q) => $q->where('material_name', 'like', "%{$search}%"))
                            ->orWhereHas('packagingMaterial', fn (Builder $q) => $q->where('packaging_name', 'like', "%{$search}%"))
                            ->orWhereHas('product', fn (Builder $q) => $q->where('product_name', 'like', "%{$search}%"));
                    }),
                TextColumn::make('quantity_in')->label('Qty In')->numeric(3),
                TextColumn::make('quantity_out')->label('Qty Out')->numeric(3),
                TextColumn::make('stock_before')->label('Stock Before')->numeric(3)->toggleable(),
                TextColumn::make('stock_after')->label('Stock After')->numeric(3),
                TextColumn::make('old_average_rate')->label('Old Avg')->money('INR')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rate')->label('Eff. Rate')->money('INR')->toggleable(),
                TextColumn::make('new_average_rate')->label('New Avg')->money('INR')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('transaction_value')->label('Value')->money('INR'),
                TextColumn::make('reference_number')->label('Inward No.')->placeholder('-')->toggleable(),
                TextColumn::make('supplier_invoice_number')->label('Invoice No.')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('batch_number')->label('Batch')->placeholder('-')->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('item_type')
                    ->label('Item Type')
                    ->options(StockItemType::options()),
                SelectFilter::make('transaction_type')
                    ->label('Transaction Type')
                    ->options(StockTransactionType::options()),
                Filter::make('transaction_date')
                    ->schema([
                        DatePicker::make('from')->native(false),
                        DatePicker::make('until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['from'] ?? null), fn (Builder $q): Builder => $q->whereDate('transaction_date', '>=', $data['from']))
                            ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->whereDate('transaction_date', '<=', $data['until']));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ])
            ->defaultSort('id', 'desc');
    }
}
