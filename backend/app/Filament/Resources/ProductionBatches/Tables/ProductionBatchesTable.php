<?php

namespace App\Filament\Resources\ProductionBatches\Tables;

use App\Enums\ProductionBatchStatus;
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
                TextColumn::make('product_display')
                    ->label('Product')
                    ->state(fn (ProductionBatch $record): ?string => $record->outputDisplayLabel() ?: null)
                    ->placeholder('—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.$search.'%';

                        return $query->where(function (Builder $q) use ($like): void {
                            $q->whereHas('product', function (Builder $productQuery) use ($like): void {
                                $productQuery->withTrashed()
                                    ->where(function (Builder $inner) use ($like): void {
                                        $inner->where('product_name', 'like', $like)
                                            ->orWhere('product_code', 'like', $like);
                                    });
                            })->orWhereHas('semiFinished', function (Builder $sfQuery) use ($like): void {
                                $sfQuery->where(function (Builder $inner) use ($like): void {
                                    $inner->where('material_name', 'like', $like)
                                        ->orWhere('material_code', 'like', $like);
                                });
                            });
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query->orderByRaw(
                            'COALESCE(
                                (SELECT product_name FROM products WHERE products.id = production_batches.product_id LIMIT 1),
                                (SELECT material_name FROM semi_finished_materials WHERE semi_finished_materials.id = production_batches.semi_finished_id LIMIT 1)
                            ) '.$dir
                        );
                    }),
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
