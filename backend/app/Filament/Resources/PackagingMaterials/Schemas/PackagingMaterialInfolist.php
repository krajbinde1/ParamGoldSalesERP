<?php

namespace App\Filament\Resources\PackagingMaterials\Schemas;

use App\Enums\PackagingType;
use App\Enums\StockTransactionType;
use App\Filament\Resources\PackagingMaterials\PackagingMaterialResource;
use App\Models\PackagingMaterial;
use App\Models\StockLedger;
use App\Services\Inventory\WeightedAverageCosting;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PackagingMaterialInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basic')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('packaging_code')->label('Code'),
                        TextEntry::make('packaging_name')->label('Packaging Material Name'),
                        TextEntry::make('packaging_type')
                            ->label('Packaging Type')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof PackagingType
                                ? $state->label()
                                : (string) ($state ?: '—')),
                        TextEntry::make('unit')->label('Unit'),
                        TextEntry::make('minimum_stock')->label('Minimum Stock')->numeric(3),
                        IconEntry::make('batch_tracking_enabled')->label('Batch Tracking')->boolean(),
                        IconEntry::make('expiry_tracking_enabled')->label('Expiry Tracking')->boolean(),
                        IconEntry::make('status')->label('Active')->boolean(),
                        TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Opening Stock')
                    ->description('Snapshot entered at create or edit. Available Stock = Opening Stock + Purchase Inward + Other Inward − Consumption − Other Outward. Stock Value follows those live movements.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('opening_stock')
                            ->label('Opening Stock Quantity')
                            ->numeric(3),
                        TextEntry::make('opening_stock_value_display')
                            ->label('Opening Stock Value')
                            ->state(fn (PackagingMaterial $record): string => self::formatMoney(self::openingValue($record))),
                        TextEntry::make('opening_effective_rate_display')
                            ->label('Effective Rate')
                            ->state(fn (PackagingMaterial $record): string => self::formatRate(self::openingEffectiveRate($record))),
                        TextEntry::make('opening_date_display')
                            ->label('Opening Date')
                            ->state(fn (PackagingMaterial $record): string => self::openingDate($record) ?? '—'),
                    ]),
                Section::make('Available Stock')
                    ->description('Live quantity, GST-exclusive weighted average rate, and stock value after inward, outward, production, consumption, and adjustments.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('current_stock')
                            ->label('Available Stock')
                            ->numeric(3),
                        TextEntry::make('average_rate')
                            ->label('Average Stock Rate')
                            ->state(fn (PackagingMaterial $record): string => app(WeightedAverageCosting::class)->formatRate(
                                (float) $record->average_rate,
                                $record->unit,
                            ))
                            ->visible(fn (): bool => PackagingMaterialResource::canViewPurchaseRates()),
                        TextEntry::make('current_stock_value')
                            ->label('Stock Value')
                            ->money('INR')
                            ->visible(fn (): bool => PackagingMaterialResource::canViewPurchaseRates()),
                    ]),
                Section::make('System')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')->label('Created At')->dateTime(),
                        TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                    ]),
            ]);
    }

    private static function openingLedger(PackagingMaterial $record): ?StockLedger
    {
        return $record->stockLedgers()
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->orderBy('id')
            ->first();
    }

    private static function openingEffectiveRate(PackagingMaterial $record): float
    {
        $ledger = self::openingLedger($record);

        if ($ledger !== null) {
            return (float) $ledger->rate;
        }

        return (float) $record->purchase_rate;
    }

    private static function openingValue(PackagingMaterial $record): float
    {
        $ledger = self::openingLedger($record);

        if ($ledger !== null) {
            return (float) $ledger->transaction_value;
        }

        $qty = (float) $record->opening_stock;
        $rate = self::openingEffectiveRate($record);

        return round($qty * $rate, 2);
    }

    private static function openingDate(PackagingMaterial $record): ?string
    {
        $ledger = self::openingLedger($record);

        if ($ledger?->transaction_date !== null) {
            return $ledger->transaction_date->format('d-m-Y');
        }

        if ((float) $record->opening_stock > 0 && $record->created_at !== null) {
            return $record->created_at->timezone('Asia/Kolkata')->format('d-m-Y');
        }

        return null;
    }

    private static function formatMoney(float $value): string
    {
        if ($value <= 0) {
            return '₹0.00';
        }

        return '₹'.number_format($value, 2, '.', ',');
    }

    private static function formatRate(float $rate): string
    {
        if ($rate <= 0) {
            return '—';
        }

        return '₹'.number_format($rate, 4, '.', ',');
    }
}
