<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use App\Enums\StockAdjustmentType;
use App\Enums\StockItemType;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StockAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Adjustment details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('adjustment_number')
                            ->label('Adjustment Number')
                            ->placeholder('Generated automatically when saved')
                            ->readOnly()
                            ->dehydrated(false),
                        DatePicker::make('adjustment_date')
                            ->native(false)
                            ->required()
                            ->default(fn () => now('Asia/Kolkata')->toDateString()),
                        Select::make('adjustment_type')
                            ->label('Adjustment Type')
                            ->options(StockAdjustmentType::options())
                            ->required()
                            ->live(),
                        Select::make('item_type')
                            ->label('Item Type')
                            ->options(StockItemType::options())
                            ->required()
                            ->live(),
                        Select::make('raw_material_id')
                            ->label('Raw Material')
                            ->relationship('rawMaterial', 'material_name', fn (Builder $query) => $query->where('status', true))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('item_type') === StockItemType::RawMaterial->value)
                            ->required(fn (Get $get): bool => $get('item_type') === StockItemType::RawMaterial->value)
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $material = RawMaterial::query()->find($state);
                                $set('system_stock', $material?->current_stock);
                            }),
                        Select::make('packaging_material_id')
                            ->label('Packaging Material')
                            ->relationship('packagingMaterial', 'packaging_name', fn (Builder $query) => $query->where('status', true))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('item_type') === StockItemType::PackagingMaterial->value)
                            ->required(fn (Get $get): bool => $get('item_type') === StockItemType::PackagingMaterial->value)
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $material = PackagingMaterial::query()->find($state);
                                $set('system_stock', $material?->current_stock);
                            }),
                        Select::make('semi_finished_id')
                            ->label('Semi-Finished Material')
                            ->relationship('semiFinished', 'material_name', fn (Builder $query) => $query->where('status', true))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('item_type') === StockItemType::SemiFinished->value)
                            ->required(fn (Get $get): bool => $get('item_type') === StockItemType::SemiFinished->value)
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $material = SemiFinishedMaterial::query()->find($state);
                                $set('system_stock', $material?->current_stock);
                            }),
                        Select::make('product_id')
                            ->label('Finished Product')
                            ->relationship('product', 'product_name', fn (Builder $query) => $query->where('manufacturing_enabled', true))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('item_type') === StockItemType::FinishedProduct->value)
                            ->required(fn (Get $get): bool => $get('item_type') === StockItemType::FinishedProduct->value)
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $product = Product::query()->find($state);
                                $set('system_stock', $product?->current_finished_stock);
                            }),
                        TextInput::make('system_stock')
                            ->label('System Stock (reference)')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Live stock at the time of posting may differ slightly; the exact value is locked and recorded during processing.'),
                    ]),
                Section::make('Quantity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('physical_stock')
                            ->label('Physical Stock (counted)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => $get('adjustment_type') === StockAdjustmentType::PhysicalStockCorrection->value)
                            ->required(fn (Get $get): bool => $get('adjustment_type') === StockAdjustmentType::PhysicalStockCorrection->value)
                            ->dehydrated(),
                        TextInput::make('adjusted_quantity')
                            ->label('Adjusted Quantity')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => $get('adjustment_type') !== StockAdjustmentType::PhysicalStockCorrection->value)
                            ->required(fn (Get $get): bool => $get('adjustment_type') !== StockAdjustmentType::PhysicalStockCorrection->value)
                            ->dehydrated(),
                    ]),
                Section::make('Reason & attachments')
                    ->columns(2)
                    ->schema([
                        TextInput::make('reason')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('remarks')
                            ->rows(2)
                            ->columnSpanFull(),
                        FileUpload::make('attachment_path')
                            ->label('Supporting Document')
                            ->directory('stock-adjustments')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
