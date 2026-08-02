<?php

namespace App\Filament\Resources\Boms\Schemas;

use App\Enums\BomItemType;
use App\Enums\BomStatus;
use App\Models\Bom;
use App\Models\BomItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BomInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $showCosts = fn (): bool => auth()->user()?->canViewProductionCosts() ?? false;

        return $schema
            ->components([
                Section::make('BOM details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('bom_number')->label('BOM Number'),
                        TextEntry::make('product.product_name')
                            ->label('Product')
                            ->formatStateUsing(fn ($state, Bom $record): string => $record->product?->displayLabel() ?? (string) $state),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (BomStatus $state): string => $state->label())
                            ->color(fn (BomStatus $state): string => $state->color()),
                        TextEntry::make('effective_date')->date(),
                        TextEntry::make('batch_quantity')
                            ->label('Formula For Quantity')
                            ->numeric(3),
                        TextEntry::make('batch_unit')->label('Batch Unit'),
                        TextEntry::make('notes')->columnSpanFull()->placeholder('-'),
                    ]),
                Section::make('BOM items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('item_type')
                                    ->label('Item Type')
                                    ->badge()
                                    ->formatStateUsing(fn (BomItemType $state): string => $state->label()),
                                TextEntry::make('materialName')
                                    ->label('Material')
                                    ->state(fn (BomItem $record): string => $record->materialName()),
                                TextEntry::make('inventory_unit')
                                    ->label('Inventory Unit')
                                    ->placeholder('-'),
                                TextEntry::make('unit')->label('Formulation Unit'),
                                TextEntry::make('required_quantity')->label('Required Quantity')->numeric(4),
                                TextEntry::make('inventory_equivalent_quantity')
                                    ->label('Inventory Equivalent')
                                    ->state(function (BomItem $record): string {
                                        $qty = $record->inventory_equivalent_quantity;
                                        $unit = $record->inventory_unit ?: $record->unit;

                                        if ($qty === null) {
                                            return '—';
                                        }

                                        return number_format((float) $qty, 3).' '.$unit;
                                    }),
                                TextEntry::make('remarks')->placeholder('-')->columnSpanFull(),
                            ]),
                    ]),
                Section::make('BOM Summary')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('formula_for')
                            ->label('Formula For')
                            ->state(fn (Bom $record): string => (string) ($record->formulaSummary()['formula_for_label'] ?? '—')),
                        TextEntry::make('formula_quantity_summary')
                            ->label('Formula Quantity')
                            ->state(fn (Bom $record): string => number_format((float) ($record->formulaSummary()['formula_quantity'] ?? 0), 3)),
                        TextEntry::make('formula_unit')
                            ->label('Formula Unit')
                            ->state(fn (Bom $record): string => (string) ($record->formulaSummary()['formula_unit'] ?? '—')),
                        TextEntry::make('total_items')
                            ->label('Total Items')
                            ->state(fn (Bom $record): int => (int) ($record->formulaSummary()['total_items'] ?? 0)),
                        TextEntry::make('raw_material_items')
                            ->label('Raw Material Items')
                            ->state(fn (Bom $record): int => (int) ($record->formulaSummary()['raw_material_items'] ?? 0)),
                        TextEntry::make('packaging_material_items')
                            ->label('Packaging Material Items')
                            ->state(fn (Bom $record): int => (int) ($record->formulaSummary()['packaging_material_items'] ?? 0)),
                        TextEntry::make('estimated_raw_material_cost')
                            ->label('Estimated Raw Material Cost')
                            ->state(fn (Bom $record): string => '₹'.number_format((float) ($record->formulaSummary()['estimated_raw_material_cost'] ?? 0), 2))
                            ->visible($showCosts),
                        TextEntry::make('estimated_packaging_cost')
                            ->label('Estimated Packaging Cost')
                            ->state(fn (Bom $record): string => '₹'.number_format((float) ($record->formulaSummary()['estimated_packaging_cost'] ?? 0), 2))
                            ->visible($showCosts),
                        TextEntry::make('estimated_total_bom_cost')
                            ->label('Estimated Total BOM Cost')
                            ->state(fn (Bom $record): string => '₹'.number_format((float) ($record->formulaSummary()['estimated_total_bom_cost'] ?? 0), 2))
                            ->visible($showCosts),
                        TextEntry::make('estimated_cost_per_finished_unit')
                            ->label('Estimated Cost Per Unit')
                            ->state(function (Bom $record): string {
                                $value = $record->formulaSummary()['estimated_cost_per_finished_unit'] ?? null;

                                return $value !== null
                                    ? '₹'.number_format((float) $value, 2)
                                    : '—';
                            })
                            ->visible($showCosts),
                    ]),
            ]);
    }
}
