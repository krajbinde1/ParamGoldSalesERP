<?php

namespace App\Filament\Resources\Boms\Tables;

use App\Enums\BomStatus;
use App\Filament\Resources\Boms\BomResource;
use App\Models\Bom;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bom_number')
                    ->label('BOM Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.product_name')
                    ->label('Product')
                    ->formatStateUsing(fn ($state, Bom $record): string => $record->product?->displayLabel() ?? (string) $state)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BomStatus $state): string => $state->label())
                    ->color(fn (BomStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('effective_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('batch_quantity')
                    ->label('Formula For Quantity')
                    ->formatStateUsing(fn ($state, Bom $record): string => $record->formulaQuantityLabel())
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(BomStatus::options()),
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'product_name')
                    ->searchable()
                    ->preload(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->authorize(fn (Bom $record): bool => BomResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => BomResource::canDeleteAny()),
                ]),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }
}
