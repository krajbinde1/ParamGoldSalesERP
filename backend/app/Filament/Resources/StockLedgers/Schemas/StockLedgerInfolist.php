<?php

namespace App\Filament\Resources\StockLedgers\Schemas;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockLedgerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaction details')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('transaction_date')->date(),
                        TextEntry::make('transaction_type')
                            ->badge()
                            ->formatStateUsing(fn (StockTransactionType $state): string => $state->label()),
                        TextEntry::make('item_type')
                            ->label('Item Type')
                            ->badge()
                            ->formatStateUsing(fn (StockItemType $state): string => $state->label()),
                        TextEntry::make('rawMaterial.material_name')->label('Raw Material')->placeholder('-'),
                        TextEntry::make('packagingMaterial.packaging_name')->label('Packaging Material')->placeholder('-'),
                        TextEntry::make('product.product_name')->label('Finished Product')->placeholder('-'),
                        TextEntry::make('reference_number')->label('Inward / Reference No.')->placeholder('-'),
                        TextEntry::make('supplier_invoice_number')->label('Supplier Invoice Number')->placeholder('-'),
                        TextEntry::make('batch_number')->label('Batch Number')->placeholder('-'),
                        TextEntry::make('createdBy.name')->label('Recorded By')->placeholder('-'),
                    ]),
                Section::make('Movement')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('quantity_in')->label('Quantity In')->numeric(3),
                        TextEntry::make('quantity_out')->label('Quantity Out')->numeric(3),
                        TextEntry::make('stock_before')->label('Stock Before')->numeric(3),
                        TextEntry::make('stock_after')->label('Stock After')->numeric(3),
                        TextEntry::make('old_average_rate')->label('Old Average Rate')->money('INR')->placeholder('-'),
                        TextEntry::make('rate')->label('Inward Effective Rate')->money('INR'),
                        TextEntry::make('new_average_rate')->label('New Average Rate')->money('INR')->placeholder('-'),
                        TextEntry::make('transaction_value')->label('Transaction Value')->money('INR'),
                    ]),
                Section::make('Remarks')
                    ->schema([
                        TextEntry::make('remarks')->columnSpanFull()->placeholder('-'),
                    ]),
            ]);
    }
}
