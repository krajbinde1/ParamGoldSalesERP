<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use App\Enums\StockAdjustmentType;
use App\Enums\StockItemType;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockAdjustmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Adjustment details')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('adjustment_number')->label('Adjustment Number'),
                        TextEntry::make('adjustment_date')->date(),
                        TextEntry::make('adjustment_type')
                            ->badge()
                            ->formatStateUsing(fn (StockAdjustmentType $state): string => $state->label()),
                        TextEntry::make('item_type')
                            ->label('Item Type')
                            ->badge()
                            ->formatStateUsing(fn (StockItemType $state): string => $state->label()),
                        TextEntry::make('rawMaterial.material_name')->label('Raw Material')->placeholder('-'),
                        TextEntry::make('packagingMaterial.packaging_name')->label('Packaging Material')->placeholder('-'),
                        TextEntry::make('product.product_name')->label('Finished Product')->placeholder('-'),
                        TextEntry::make('approvedBy.name')->label('Approved By')->placeholder('-'),
                        TextEntry::make('createdBy.name')->label('Created By')->placeholder('-'),
                    ]),
                Section::make('Quantity & value')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('system_stock')->label('System Stock')->numeric(3),
                        TextEntry::make('adjusted_quantity')->label('Adjusted Quantity')->numeric(3),
                        TextEntry::make('stock_after')->label('Stock After')->numeric(3),
                        TextEntry::make('rate')->money('INR'),
                        TextEntry::make('adjustment_value')->label('Value')->money('INR'),
                    ]),
                Section::make('Reason & remarks')
                    ->schema([
                        TextEntry::make('reason')->columnSpanFull(),
                        TextEntry::make('remarks')->columnSpanFull()->placeholder('-'),
                    ]),
            ]);
    }
}
