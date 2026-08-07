<?php

namespace App\Filament\Resources\FinishedProducts\Schemas;

use App\Enums\StockTransactionType;
use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use App\Models\Product;
use App\Models\StockLedger;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FinishedProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sales Product')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('product_code')->label('Product Code'),
                        TextEntry::make('product_name')->label('Product Name'),
                        TextEntry::make('finished_product_code')
                            ->label('FP Code (legacy)')
                            ->state(fn (Product $record): string => (string) ($record->finishedProduct?->finished_product_code ?? '—'))
                            ->visible(fn (Product $record): bool => filled($record->finishedProduct?->finished_product_code)),
                        TextEntry::make('unit')
                            ->label('Unit')
                            ->state(fn (Product $record): string => (string) (
                                $record->production_unit
                                ?: $record->uom
                                ?: ($record->finishedProduct?->unit ?? '')
                            )),
                        TextEntry::make('minimum_finished_stock')->label('Minimum Stock')->numeric(3),
                        IconEntry::make('batch_tracking_enabled')->label('Batch Tracking')->boolean(),
                        IconEntry::make('expiry_tracking_enabled')->label('Expiry Tracking')->boolean(),
                        IconEntry::make('status')->label('Active')->boolean(),
                        TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Opening Stock')
                    ->description('As entered via Set Opening Stock or Finished Goods Opening Stock Import. Current stock and ledger live under Inventory Stock Report.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('opening_finished_stock')
                            ->label('Opening Stock Quantity')
                            ->numeric(3),
                        TextEntry::make('opening_stock_value_display')
                            ->label('Opening Stock Value')
                            ->state(fn (Product $record): string => self::formatMoney(self::openingValue($record))),
                        TextEntry::make('opening_effective_rate_display')
                            ->label('Effective Rate')
                            ->state(fn (Product $record): string => self::formatRate(self::openingEffectiveRate($record))),
                        TextEntry::make('opening_date_display')
                            ->label('Opening Date')
                            ->state(fn (Product $record): string => self::openingDate($record) ?? '—'),
                    ]),
                Section::make('Stock Summary')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('current_finished_stock')->label('Available Quantity')->numeric(3),
                        TextEntry::make('current_stock_value')
                            ->label('Inventory Value')
                            ->state(fn (Product $record): float => $record->current_stock_value)
                            ->money('INR')
                            ->visible(fn (): bool => FinishedProductResource::canViewCosts()),
                        TextEntry::make('weighted_average_cost')
                            ->label('Avg / Effective Rate')
                            ->money('INR')
                            ->visible(fn (): bool => FinishedProductResource::canViewCosts()),
                    ]),
                Section::make('System')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')->label('Created At')->dateTime(),
                        TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                    ]),
            ]);
    }

    private static function openingLedger(Product $record): ?StockLedger
    {
        return $record->stockLedgers()
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->orderBy('id')
            ->first();
    }

    private static function openingEffectiveRate(Product $record): float
    {
        $ledger = self::openingLedger($record);

        if ($ledger !== null) {
            return (float) $ledger->rate;
        }

        return (float) $record->weighted_average_cost;
    }

    private static function openingValue(Product $record): float
    {
        $ledger = self::openingLedger($record);

        if ($ledger !== null) {
            return (float) $ledger->transaction_value;
        }

        $qty = (float) $record->opening_finished_stock;
        $rate = self::openingEffectiveRate($record);

        return round($qty * $rate, 2);
    }

    private static function openingDate(Product $record): ?string
    {
        $ledger = self::openingLedger($record);

        if ($ledger?->transaction_date !== null) {
            return $ledger->transaction_date->format('d-m-Y');
        }

        if ((float) $record->opening_finished_stock > 0 && $record->created_at !== null) {
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
