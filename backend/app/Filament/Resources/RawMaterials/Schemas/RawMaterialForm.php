<?php

namespace App\Filament\Resources\RawMaterials\Schemas;

use App\Enums\InventoryUnit;
use App\Filament\Resources\RawMaterials\RawMaterialResource;
use App\Models\RawMaterial;
use App\Services\Inventory\MaterialEffectiveRate;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class RawMaterialForm
{
    /**
     * Shared material master fields (Create + Edit).
     *
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function materialDetailsComponents(): array
    {
        return [
            Section::make('Material Details')
                ->columns(2)
                ->schema([
                    TextInput::make('material_code')
                        ->label('Material Code')
                        ->placeholder('Generated automatically when saved')
                        ->readOnly()
                        ->dehydrated(false),
                    TextInput::make('material_name')
                        ->label('Material Name')
                        ->required()
                        ->maxLength(255),
                    Select::make('unit')
                        ->label('Unit')
                        ->options(InventoryUnit::options())
                        ->searchable()
                        ->required()
                        ->live(),
                    TextInput::make('minimum_stock')
                        ->label('Minimum Stock Level')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    Toggle::make('batch_tracking_enabled')
                        ->label('Batch Tracking')
                        ->inline(false),
                    Toggle::make('expiry_tracking_enabled')
                        ->label('Expiry Tracking')
                        ->inline(false),
                    Toggle::make('status')
                        ->label('Active')
                        ->default(true)
                        ->inline(false),
                    Textarea::make('remarks')
                        ->label('Remarks')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * Opening stock fields — editable on Create and Edit.
     *
     * Create: posts Opening Stock ledger when quantity > 0.
     * Edit: posts opening if none exists, or updates the opening snapshot when
     * no later inventory movements have been recorded.
     *
     * Order: Quantity → Value → Effective Rate (read-only) → Opening Date.
     *
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function openingStockComponents(bool $readOnly = false): array
    {
        $description = $readOnly
            ? 'As entered at create. Opening stock is not changed on Edit (no duplicate Opening Stock ledger). Use Raw Material Inward or Stock Adjustment for later inventory changes.'
            : 'Optional. Quantity greater than zero posts or updates Opening Stock. Available Stock and Stock Value always follow live inventory after inward, outward, production, consumption, and adjustment — they are not frozen at this opening. After other stock movements, opening quantity and value cannot be changed here.';

        return [
            Section::make('Opening Stock')
                ->description($description)
                ->columns(2)
                ->schema([
                    TextInput::make('opening_stock_quantity')
                        ->label('Opening Stock Quantity')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(fn (): bool => ! $readOnly)
                        ->disabled($readOnly)
                        ->dehydrated(fn (): bool => ! $readOnly)
                        ->live(debounce: 300)
                        ->suffix(fn (Get $get): string => filled($get('unit')) ? (string) $get('unit') : '')
                        ->helperText(fn (Get $get): ?string => $readOnly
                            ? null
                            : (filled($get('unit'))
                                ? null
                                : 'Select Default Unit in Material Details before entering quantity.'))
                        ->rules($readOnly ? [] : [
                            fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                $qty = (float) ($value ?? 0);
                                if ($qty < 0 || ! is_numeric($value ?? 0)) {
                                    $fail('Opening Stock Quantity must be zero or greater.');
                                }
                                if ($qty > 0 && blank($get('unit'))) {
                                    $fail('Select Default Unit before entering Opening Stock Quantity.');
                                }
                            },
                        ]),
                    TextInput::make('opening_stock_value')
                        ->label('Opening Stock Value')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('₹')
                        ->live(debounce: 300)
                        ->disabled($readOnly)
                        ->dehydrated(fn (): bool => ! $readOnly)
                        ->required(fn (Get $get): bool => ! $readOnly && (float) ($get('opening_stock_quantity') ?? 0) > 0)
                        ->rules($readOnly ? [] : [
                            fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                $qty = (float) ($get('opening_stock_quantity') ?? 0);
                                $stockValue = (float) ($value ?? 0);
                                if ($stockValue < 0) {
                                    $fail('Opening Stock Value cannot be negative.');
                                }
                                if ($qty > 0 && $stockValue <= 0) {
                                    $fail('Opening Stock Value is required when Opening Stock Quantity is greater than zero.');
                                }
                                if ($qty <= 0 && $stockValue > 0) {
                                    $fail('Opening Stock Value must be zero when Opening Stock Quantity is zero.');
                                }
                            },
                        ]),
                    Placeholder::make('opening_effective_rate')
                        ->label('Effective Rate')
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            '<span class="tabular-nums font-semibold">'.e(self::formatEffectiveRate($get)).'</span>'
                        )),
                    DatePicker::make('opening_date')
                        ->label('Opening Date')
                        ->native(false)
                        ->displayFormat('d-m-Y')
                        ->default(fn (): string => now('Asia/Kolkata')->toDateString())
                        ->disabled($readOnly)
                        ->dehydrated(fn (): bool => ! $readOnly)
                        ->required(fn (Get $get): bool => ! $readOnly && (float) ($get('opening_stock_quantity') ?? 0) > 0),
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        // Default resource form (Edit): master fields, editable opening, live available stock.
        return self::configureEdit($schema);
    }

    public static function configureCreate(Schema $schema): Schema
    {
        return $schema->components([
            ...self::materialDetailsComponents(),
            ...self::openingStockComponents(readOnly: false),
        ]);
    }

    public static function configureEdit(Schema $schema): Schema
    {
        return $schema->components([
            ...self::materialDetailsComponents(),
            ...self::openingStockComponents(readOnly: false),
            ...self::currentStockComponents(),
        ]);
    }

    /**
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function currentStockComponents(): array
    {
        return [
            Section::make('Available Stock')
                ->description('Live quantity and value after inward, outward, production, consumption, and adjustments.')
                ->columns(2)
                ->schema([
                    Placeholder::make('available_stock_display')
                        ->label('Available Stock')
                        ->content(function (?RawMaterial $record): string {
                            if ($record === null) {
                                return '—';
                            }

                            $qty = number_format((float) $record->current_stock, 3, '.', '');

                            return filled($record->unit) ? $qty.' '.$record->unit : $qty;
                        }),
                    Placeholder::make('stock_value_display')
                        ->label('Stock Value')
                        ->visible(fn (): bool => RawMaterialResource::canViewPurchaseRates())
                        ->content(function (?RawMaterial $record): string {
                            if ($record === null) {
                                return '—';
                            }

                            return '₹'.number_format((float) $record->current_stock_value, 2, '.', ',');
                        }),
                    Placeholder::make('available_effective_rate_display')
                        ->label('Effective Rate')
                        ->visible(fn (): bool => RawMaterialResource::canViewPurchaseRates())
                        ->content(function (?RawMaterial $record): HtmlString {
                            if ($record === null) {
                                return new HtmlString('—');
                            }

                            return new HtmlString(
                                '<span class="tabular-nums font-semibold">'.e(app(MaterialEffectiveRate::class)->format(
                                    (float) $record->current_stock_value,
                                    (float) $record->current_stock,
                                    $record->unit,
                                )).'</span>'
                            );
                        }),
                ]),
        ];
    }

    /**
     * Effective Rate is always ₹/Kg for weight units (Ton stock is converted to Kg
     * for this display only). Stock quantity and stock value are unchanged.
     */
    private static function formatEffectiveRate(Get $get): string
    {
        return app(MaterialEffectiveRate::class)->format(
            (float) ($get('opening_stock_value') ?? 0),
            (float) ($get('opening_stock_quantity') ?? 0),
            filled($get('unit')) ? (string) $get('unit') : null,
        );
    }
}
