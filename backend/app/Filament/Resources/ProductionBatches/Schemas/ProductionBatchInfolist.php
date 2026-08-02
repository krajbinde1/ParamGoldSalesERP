<?php

namespace App\Filament\Resources\ProductionBatches\Schemas;

use App\Enums\ProductionBatchStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductionBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Batch details')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('batch_number')->label('Batch Number'),
                        TextEntry::make('product.product_name')
                            ->label('Product')
                            ->formatStateUsing(fn ($state, $record): string => $record->product?->displayLabel() ?? (string) $state),
                        TextEntry::make('bom.bom_number')->label('BOM Number')->placeholder('-'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (ProductionBatchStatus $state): string => $state->label())
                            ->color(fn (ProductionBatchStatus $state): string => $state->color()),
                        TextEntry::make('supervisor.name')->label('Posted By')->placeholder('-'),
                        TextEntry::make('production_date')->date(),
                        TextEntry::make('expiry_date')->date()->placeholder('-'),
                        TextEntry::make('completed_at')->dateTime()->placeholder('-'),
                    ]),
                Section::make('Quantities')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('bom.formula_quantity')
                            ->label('BOM Quantity')
                            ->state(fn ($record): string => $record->bom?->formulaQuantityLabel() ?? '—'),
                        TextEntry::make('actual_output_quantity')->label('Production Quantity')->numeric(3),
                        TextEntry::make('finished_packs_produced')->label('Finished Packs')->numeric(3)->placeholder('—'),
                    ]),
                Section::make('Costing')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('total_material_cost')->label('Material Cost')->money('INR'),
                        TextEntry::make('total_packaging_cost')->label('Packaging Cost')->money('INR'),
                        TextEntry::make('total_conversion_cost')->label('Conversion Cost')->money('INR'),
                        TextEntry::make('total_batch_cost')->label('Total Batch Cost')->money('INR'),
                        TextEntry::make('labour_cost')->money('INR'),
                        TextEntry::make('transport_cost')->money('INR'),
                        TextEntry::make('other_manufacturing_cost')->label('Other Cost')->money('INR'),
                        TextEntry::make('cost_per_unit')->label('Cost / Unit')->money('INR'),
                        TextEntry::make('cost_per_pack')->label('Cost / Pack')->money('INR'),
                        TextEntry::make('cost_per_case')->label('Cost / Case')->money('INR')->placeholder('—'),
                    ]),
                Section::make('Material consumption')
                    ->schema([
                        RepeatableEntry::make('consumptions')
                            ->label('')
                            ->columns(5)
                            ->schema([
                                TextEntry::make('material_name')->label('Material'),
                                TextEntry::make('unit')->label('Inventory Unit'),
                                TextEntry::make('formulation_quantity')
                                    ->label('Formulation Qty')
                                    ->formatStateUsing(function ($state, $record): string {
                                        if ($state === null) {
                                            return '—';
                                        }

                                        return number_format((float) $state, 4).' '.((string) ($record->formulation_unit ?? ''));
                                    }),
                                TextEntry::make('standard_quantity')
                                    ->label('Required (Inventory)')
                                    ->formatStateUsing(fn ($state, $record): string => number_format((float) ($state ?? $record->required_quantity), 4)),
                                TextEntry::make('consumed_quantity')->label('Consumed (Inventory)')->numeric(4),
                                TextEntry::make('rate')->money('INR'),
                                TextEntry::make('consumption_value')->label('Value')->money('INR'),
                            ]),
                    ]),
                Section::make('Finished Product Stock Posting')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('finished_product_stock_posted')
                            ->label('Finished Product Stock Posted')
                            ->state(fn ($record): string => $record->isFinishedProductStockPosted() ? 'Yes' : 'No')
                            ->badge()
                            ->color(fn ($record): string => $record->isFinishedProductStockPosted() ? 'success' : 'gray'),
                        TextEntry::make('actual_output_quantity')
                            ->label('Quantity Added')
                            ->numeric(3)
                            ->visible(fn ($record): bool => $record->isFinishedProductStockPosted()),
                        TextEntry::make('cost_per_unit')
                            ->label('Average Production Cost')
                            ->money('INR')
                            ->visible(fn ($record): bool => $record->isFinishedProductStockPosted()),
                        TextEntry::make('finished_stock_before')
                            ->label('Finished Stock Before')
                            ->numeric(3)
                            ->placeholder('—'),
                        TextEntry::make('finished_stock_after')
                            ->label('Finished Stock After')
                            ->numeric(3)
                            ->placeholder('—'),
                        TextEntry::make('finished_stock_value_after')
                            ->label('Finished Product Stock Value After')
                            ->money('INR')
                            ->placeholder('—'),
                        TextEntry::make('finished_product_ledger_id')
                            ->label('Ledger Reference')
                            ->formatStateUsing(function ($state, $record): string {
                                if (! $state) {
                                    return '—';
                                }

                                $batchNumber = $record->batch_number;

                                return "FG Production #{$state}".($batchNumber ? " ({$batchNumber})" : '');
                            })
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')->columnSpanFull()->placeholder('-'),
                        TextEntry::make('reversal_reason')->label('Reversal Reason')->columnSpanFull()->placeholder('-')
                            ->visible(fn ($record): bool => filled($record->reversal_reason)),
                    ]),
            ]);
    }
}
