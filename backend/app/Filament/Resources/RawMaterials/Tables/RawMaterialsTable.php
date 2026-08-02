<?php

namespace App\Filament\Resources\RawMaterials\Tables;

use App\Enums\InventoryUnit;
use App\Filament\Resources\RawMaterials\RawMaterialResource;
use App\Models\RawMaterial;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RawMaterialsTable
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
                    ->label('Raw Material Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->badge()
                    ->sortable(),
                TextColumn::make('minimum_stock')
                    ->label('Minimum Stock')
                    ->numeric(3)
                    ->sortable(),
                IconColumn::make('status')
                    ->label('Active Status')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('unit')
                    ->options(InventoryUnit::options()),
                TernaryFilter::make('status')
                    ->label('Active Status')
                    ->placeholder('All materials')
                    ->trueLabel('Active materials')
                    ->falseLabel('Inactive materials'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->authorize(fn (RawMaterial $record): bool => RawMaterialResource::canEdit($record)),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->defaultSort('material_name');
    }
}
