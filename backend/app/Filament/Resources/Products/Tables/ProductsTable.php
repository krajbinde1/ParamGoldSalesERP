<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
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

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('uom')
                    ->badge(),
                TextColumn::make('nos_per_case')
                    ->label('Nos/Case')
                    ->sortable(),
                TextColumn::make('gst_percentage')
                    ->label('GST')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('dealer_price')
                    ->money('INR')
                    ->sortable(),
                IconColumn::make('status')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('gst_percentage')
                    ->label('GST %')
                    ->options([
                        '0' => '0%',
                        '5' => '5%',
                        '12' => '12%',
                        '18' => '18%',
                        '28' => '28%',
                    ]),
                TernaryFilter::make('status')
                    ->label('Status')
                    ->placeholder('All products')
                    ->trueLabel('Active products')
                    ->falseLabel('Inactive products'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->authorize(fn (Product $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('deleteAny', Product::class) ?? false),
                    ForceDeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('forceDeleteAny', Product::class) ?? false),
                    RestoreBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('restoreAny', Product::class) ?? false),
                ]),
            ]);
    }
}
