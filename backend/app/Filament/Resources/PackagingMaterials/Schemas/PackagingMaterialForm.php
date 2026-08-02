<?php

namespace App\Filament\Resources\PackagingMaterials\Schemas;

use App\Enums\InventoryUnit;
use App\Services\Inventory\MaterialInwardCosting;
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
use Illuminate\Validation\ValidationException;

class PackagingMaterialForm
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
                    TextInput::make('packaging_code')
                        ->label('Material Code')
                        ->placeholder('Generated automatically when saved')
                        ->readOnly()
                        ->dehydrated(false),
                    TextInput::make('packaging_name')
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
     * Opening stock fields.
     *
     * Create: editable — posts Opening Stock ledger when quantity > 0.
     * Edit: read-only snapshot of values entered at create (never re-posts ledger).
     *
     * Order: Quantity → Value → Effective Rate (existing read-only) → Opening Date.
     *
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function openingStockComponents(bool $readOnly = false): array
    {
        $description = $readOnly
            ? 'As entered at create. Opening stock is not changed on Edit (no duplicate Opening Stock ledger). Use Packaging Material Inward or Stock Adjustment for later inventory changes.'
            : 'Optional. Enter Opening Stock Quantity greater than zero to post opening stock, update inventory and average rate, and create an Opening Stock ledger entry on Create. Leave as 0 to create the material without stock. Supplier purchases use Packaging Material Inward separately.';

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
        // Default resource form (Edit contexts via resource): master fields + read-only opening.
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
            ...self::openingStockComponents(readOnly: true),
        ]);
    }

    /**
     * Effective Rate display — same MaterialInwardCosting path as Raw Material Master.
     * With Opening Stock Value as total inventory value and no GST/freight/other
     * charges on this section, Effective Rate remains value ÷ qty (effective_unit_rate).
     */
    private static function formatEffectiveRate(Get $get): string
    {
        $qty = (float) ($get('opening_stock_quantity') ?? 0);
        $value = (float) ($get('opening_stock_value') ?? 0);

        if ($qty <= 0 || $value <= 0) {
            return '—';
        }

        try {
            $basicRate = round($value / $qty, 4);
            $calculated = app(MaterialInwardCosting::class)->calculateItemAmounts([
                'inward_quantity' => $qty,
                'basic_rate' => $basicRate,
                'discount_amount' => 0,
                'freight_amount' => 0,
                'other_charges' => 0,
                'gst_percentage' => 0,
            ]);

            return '₹'.number_format((float) $calculated['effective_unit_rate'], 4, '.', ',');
        } catch (ValidationException) {
            return '—';
        }
    }
}
