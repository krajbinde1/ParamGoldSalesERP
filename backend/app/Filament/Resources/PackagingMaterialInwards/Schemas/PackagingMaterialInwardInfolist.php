<?php

namespace App\Filament\Resources\PackagingMaterialInwards\Schemas;

use App\Filament\Resources\PackagingMaterialInwards\PackagingMaterialInwardResource;
use App\Models\PackagingMaterialInward;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PackagingMaterialInwardInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $canViewRates = PackagingMaterialInwardResource::canViewRates();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Inward Header')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'lg' => 3,
                    ])
                    ->schema([
                        TextEntry::make('inward_number')->label('Inward Number'),
                        TextEntry::make('inward_date')->label('Inward Date')->date(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state)
                            ->color(fn (PackagingMaterialInward $record): string => $record->status->color()),
                        TextEntry::make('supplier_name')
                            ->label('Supplier Name')
                            ->formatStateUsing(fn (?string $state, PackagingMaterialInward $record): string => $record->displaySupplierName()),
                        TextEntry::make('supplier_invoice_number')->label('Supplier Invoice Number'),
                        TextEntry::make('supplier_invoice_date')->label('Invoice Date')->date(),
                        TextEntry::make('createdBy.name')->label('Created By'),
                        TextEntry::make('posted_at')->dateTime(),
                        TextEntry::make('remarks')->columnSpanFull(),
                    ]),
                Section::make('Inward Summary')
                    ->columns([
                        'default' => 2,
                        'md' => 3,
                        'lg' => 4,
                    ])
                    ->schema([
                        TextEntry::make('total_materials')
                            ->label('Total Materials')
                            ->state(fn (PackagingMaterialInward $record): int => $record->items->count()),
                        TextEntry::make('total_accepted_qty')->label('Total Inward Quantity')->numeric(3),
                        TextEntry::make('total_basic_value')->label('Total Basic Value')->money('INR')->visible($canViewRates),
                        TextEntry::make('total_discount')->label('Total Discount')->money('INR')->visible($canViewRates),
                        TextEntry::make('total_freight')->label('Total Freight')->money('INR')->visible($canViewRates),
                        TextEntry::make('total_other_charges')->label('Total Other Charges')->money('INR')->visible($canViewRates),
                        TextEntry::make('total_taxable_value')->label('Total Taxable Value')->money('INR')->visible($canViewRates),
                        TextEntry::make('total_gst')->label('Total GST')->money('INR')->visible($canViewRates),
                        TextEntry::make('grand_total')->label('Grand Total')->money('INR')->visible($canViewRates),
                    ]),
                Section::make('Material Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->schema([
                                TextEntry::make('material_code')->label('Code'),
                                TextEntry::make('material_name')->label('Packaging Material'),
                                TextEntry::make('stock_before')
                                    ->label('Current Stock Before Inward')
                                    ->numeric(3)
                                    ->state(function ($state, $record): float {
                                        if ($state !== null) {
                                            return (float) $state;
                                        }

                                        return (float) ($record->packagingMaterial?->current_stock ?? 0);
                                    }),
                                TextEntry::make('old_average_rate')
                                    ->label('Old Average Rate')
                                    ->money('INR')
                                    ->visible($canViewRates)
                                    ->state(function ($state, $record): float {
                                        if ($state !== null) {
                                            return (float) $state;
                                        }

                                        return (float) ($record->packagingMaterial?->average_rate ?? 0);
                                    }),
                                TextEntry::make('accepted_quantity')->label('Inward Quantity')->numeric(3),
                                TextEntry::make('unit')->label('Unit'),
                                TextEntry::make('basic_rate')->label('Purchase Rate')->money('INR')->visible($canViewRates),
                                TextEntry::make('discount_amount')->label('Discount')->money('INR')->visible($canViewRates),
                                TextEntry::make('other_charges')->label('Other Taxable Charges')->money('INR')->visible($canViewRates),
                                TextEntry::make('taxable_amount')->label('Taxable Amount')->money('INR')->visible($canViewRates),
                                TextEntry::make('freight_amount')->label('Freight Charges')->money('INR')->visible($canViewRates),
                                TextEntry::make('gst_percentage')->label('GST %')->visible($canViewRates),
                                TextEntry::make('igst_amount')->label('GST Amount')->money('INR')->visible($canViewRates),
                                TextEntry::make('landed_cost')->label('Effective Inventory Value')->money('INR')->visible($canViewRates),
                                TextEntry::make('effective_unit_rate')->label('Effective Rate')->money('INR')->visible($canViewRates),
                                TextEntry::make('stock_after')
                                    ->label('Stock After Inward')
                                    ->numeric(3)
                                    ->state(function ($state, $record): float {
                                        if ($state !== null) {
                                            return (float) $state;
                                        }

                                        $before = (float) ($record->stock_before ?? $record->packagingMaterial?->current_stock ?? 0);

                                        return round($before + (float) $record->accepted_quantity, 3);
                                    }),
                                TextEntry::make('new_average_rate')
                                    ->label('New Average Rate')
                                    ->money('INR')
                                    ->visible($canViewRates)
                                    ->state(function ($state, $record) use ($canViewRates): ?float {
                                        if (! $canViewRates) {
                                            return null;
                                        }
                                        if ($state !== null) {
                                            return (float) $state;
                                        }

                                        $oldStock = (float) ($record->stock_before ?? $record->packagingMaterial?->current_stock ?? 0);
                                        $oldAvg = (float) ($record->old_average_rate ?? $record->packagingMaterial?->average_rate ?? 0);
                                        $qty = (float) $record->accepted_quantity;
                                        $eff = (float) $record->effective_unit_rate;
                                        $newStock = $oldStock + $qty;
                                        if ($qty <= 0) {
                                            return $oldAvg;
                                        }
                                        if ($oldStock <= 0 || $newStock <= 0) {
                                            return $eff;
                                        }

                                        return round((($oldStock * $oldAvg) + ($qty * $eff)) / $newStock, 4);
                                    }),
                                TextEntry::make('total_amount')->label('Total Amount')->money('INR')->visible($canViewRates),
                                TextEntry::make('remarks')->columnSpanFull(),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                'lg' => 3,
                            ]),
                    ]),
            ]);
    }
}
