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
                        TextEntry::make('remarks')->label('Remark')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('invoice_path')
                            ->label('Purchase Invoice')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Supplier Purchase Bill')
                    ->description('Matches the supplier material invoice. Transport/Freight is not included.')
                    ->columns(3)
                    ->visible($canViewRates)
                    ->schema([
                        TextEntry::make('total_taxable_amount')->label('Material Bill Taxable Amount')->money('INR'),
                        TextEntry::make('total_gst')->label('GST')->money('INR'),
                        TextEntry::make('grand_total')->label('Supplier Bill Grand Total')->money('INR'),
                    ]),
                Section::make('Transport / Freight')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('transport_cost')
                            ->label('Transport/Freight Cost')
                            ->money('INR')
                            ->visible($canViewRates),
                        TextEntry::make('transporter_name')->label('Transporter Name')->placeholder('—'),
                        TextEntry::make('transport_invoice_lr_no')->label('Transport Invoice/LR No.')->placeholder('—'),
                        TextEntry::make('transport_remark')->label('Transport Remark')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('total_landed_cost')
                            ->label('Total Landed Cost')
                            ->money('INR')
                            ->helperText('Material taxable value + transport/freight. GST is excluded.')
                            ->visible($canViewRates),
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
                                TextEntry::make('purchase_rate')->label('Purchase Rate')->money('INR')->visible($canViewRates),
                                TextEntry::make('taxable_amount')->label('Taxable')->money('INR')->visible($canViewRates),
                                TextEntry::make('gst_percentage')->label('GST %')->visible($canViewRates),
                                TextEntry::make('gst_amount')->label('GST Amount')->money('INR')->visible($canViewRates),
                                TextEntry::make('total_amount')->label('Supplier Line Total')->money('INR')->visible($canViewRates),
                                TextEntry::make('allocated_transport_cost')->label('Allocated Transport Cost')->money('INR')->visible($canViewRates),
                                TextEntry::make('effective_unit_rate')->label('Effective/Landed Rate')->money('INR')->visible($canViewRates),
                                TextEntry::make('landed_cost')->label('Landed Material Cost')->money('INR')->visible($canViewRates),
                                TextEntry::make('batch_lot_no')->label('Batch/Lot No.')->placeholder('—'),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
