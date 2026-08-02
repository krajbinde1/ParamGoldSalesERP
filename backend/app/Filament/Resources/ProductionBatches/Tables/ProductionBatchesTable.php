<?php

namespace App\Filament\Resources\ProductionBatches\Tables;

use App\Enums\ProductionBatchStatus;
use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use App\Models\ProductionBatch;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductionBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_number')
                    ->label('Batch Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.product_name')
                    ->label('Product')
                    ->formatStateUsing(fn ($state, ProductionBatch $record): string => $record->product?->displayLabel() ?? (string) $state)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('production_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('actual_output_quantity')
                    ->label('Production Qty')
                    ->numeric(3),
                TextColumn::make('total_batch_cost')
                    ->label('Batch Cost')
                    ->money('INR'),
                TextColumn::make('cost_per_pack')
                    ->label('Cost/Pack')
                    ->money('INR'),
                TextColumn::make('cost_per_unit')
                    ->label('Cost/Unit')
                    ->money('INR')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ProductionBatchStatus $state): string => $state->label())
                    ->color(fn (ProductionBatchStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('supervisor.name')
                    ->label('Posted By')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'product_name')
                    ->searchable()
                    ->preload(false),
                SelectFilter::make('status')
                    ->options(ProductionBatchStatus::options()),
                Filter::make('production_date')
                    ->schema([
                        DatePicker::make('from')->native(false),
                        DatePicker::make('until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['from'] ?? null), fn (Builder $q): Builder => $q->whereDate('production_date', '>=', $data['from']))
                            ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->whereDate('production_date', '<=', $data['until']));
                    }),
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
