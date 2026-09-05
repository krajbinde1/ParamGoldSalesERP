<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\PurchaseMaterialType;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $canViewRates = PurchaseResource::canViewRates();

        return $schema
            ->components([
                Section::make('Purchase')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('purchase_number')->label('Purchase No.'),
                        TextEntry::make('purchase_date')->label('Purchase Date')->date(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state instanceof PurchaseStatus ? $state->label() : (string) $state)
                            ->color(fn (Purchase $record): string => $record->status->color()),
                        TextEntry::make('supplier_name')
                            ->label('Supplier')
                            ->state(fn (Purchase $record): string => $record->displaySupplierName()),
                        TextEntry::make('supplier_invoice_number')->label('Invoice No.')->placeholder('—'),
                        TextEntry::make('supplier_invoice_date')->label('Invoice Date')->date()->placeholder('—'),
                        TextEntry::make('material_type')
                            ->label('Material Type')
                            ->formatStateUsing(fn ($state) => $state instanceof PurchaseMaterialType ? $state->label() : (string) $state),
                        TextEntry::make('total_quantity')->label('Total Qty')->numeric(3),
                        TextEntry::make('total_taxable_amount')->label('Taxable Amount')->money('INR')->visible($canViewRates),
                        TextEntry::make('total_gst')->label('GST')->money('INR')->visible($canViewRates),
                        TextEntry::make('grand_total')->label('Grand Total')->money('INR')->visible($canViewRates),
                        TextEntry::make('remarks')->label('Remark')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('invoice_path')
                            ->label('Purchase Invoice')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->schema([
                                TextEntry::make('material')
                                    ->label('Material')
                                    ->state(fn (PurchaseItem $record): string => $record->materialName()),
                                TextEntry::make('quantity')->numeric(3),
                                TextEntry::make('unit')->label('UOM'),
                                TextEntry::make('purchase_rate')->label('Purchase Rate (ex GST)')->money('INR')->visible($canViewRates),
                                TextEntry::make('taxable_amount')->label('Taxable')->money('INR')->visible($canViewRates),
                                TextEntry::make('gst_percentage')->label('GST %')->visible($canViewRates),
                                TextEntry::make('gst_amount')->label('GST Amount')->money('INR')->visible($canViewRates),
                                TextEntry::make('total_amount')->label('Total')->money('INR')->visible($canViewRates),
                                TextEntry::make('batch_lot_no')->label('Batch/Lot No.')->placeholder('—'),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
