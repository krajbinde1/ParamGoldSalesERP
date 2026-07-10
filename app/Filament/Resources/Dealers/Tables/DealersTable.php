<?php

namespace App\Filament\Resources\Dealers\Tables;

use App\Models\Dealer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

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
                    ->searchable(),
                TextColumn::make('mobile')
                    ->searchable(),
                TextColumn::make('alternate_mobile')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('gst_no')
                    ->label('GSTIN')
                    ->searchable(),
                TextColumn::make('dealer_type')
                    ->badge()
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
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('outstanding')
                    ->money('INR')
                    ->sortable(),
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
