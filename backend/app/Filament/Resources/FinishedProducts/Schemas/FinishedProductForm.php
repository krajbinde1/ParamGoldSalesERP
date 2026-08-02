<?php

namespace App\Filament\Resources\FinishedProducts\Schemas;

use App\Enums\InventoryUnit;
use App\Models\Product;
use App\Services\Inventory\MaterialInwardCosting;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class FinishedProductForm
{
    /**
     * @param  array<string, int>|int|string|null  $columnSpan
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function productDetailsComponents(
        bool $forEdit = false,
        bool $unitLocked = false,
        array|int|string|null $columnSpan = null,
    ): array {
        $section = Section::make('Product Details')
            ->columns(2)
            ->schema([
                TextInput::make('finished_product_code')
                    ->label('Finished Product Code')
                    ->placeholder('Generated automatically when saved')
                    ->readOnly()
                    ->dehydrated(false)
                    ->visible($forEdit),
                TextInput::make('product_code')
                    ->label('Product Code')
                    ->placeholder('Generated automatically when saved')
                    ->readOnly()
                    ->dehydrated(false),
                TextInput::make('product_name')
                    ->label('Product Name')
                    ->required()
                    ->maxLength(255),
                ...($forEdit
                    ? [
                        Placeholder::make('linked_sales_product_display')
                            ->label('Linked Sales Product')
                            ->content(fn (?Product $record): string => $record?->displayLabel() ?? '—')
                            ->helperText('Sales product identity (1:1 with Finished Product Inventory).')
                            ->columnSpanFull(),
                    ]
                    : [
                        Select::make('linked_product_id')
                            ->label('Link Existing Sales Product')
                            ->helperText('Optional. Select an existing sales product to manage as finished inventory. Each sales product can be linked only once.')
                            ->options(fn (): array => Product::query()
                                ->availableForFinishedProductLink()
                                ->orderBy('product_name')
                                ->get(['id', 'product_code', 'product_name'])
                                ->mapWithKeys(fn (Product $product): array => [
                                    $product->id => $product->displayLabel(),
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (blank($state)) {
                                    $set('product_code', null);

                                    return;
                                }

                                $product = Product::query()->find($state);
                                if ($product === null) {
                                    return;
                                }

                                $set('product_code', $product->product_code);
                                $set('product_name', $product->product_name);
                                $set('unit', $product->production_unit ?: $product->uom);
                            })
                            ->columnSpanFull(),
                    ]),
                Select::make('unit')
                    ->label('Unit')
                    ->options(InventoryUnit::options())
                    ->searchable()
                    ->required()
                    ->live()
                    ->disabled($unitLocked)
                    ->dehydrated(fn (): bool => ! $unitLocked),
                TextInput::make('minimum_finished_stock')
                    ->label('Minimum Stock Level')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Toggle::make('batch_tracking_enabled')
                    ->label('Batch Tracking')
                    ->default(true)
                    ->inline(false),
                Toggle::make('expiry_tracking_enabled')
                    ->label('Expiry Tracking')
                    ->default(false)
                    ->inline(false),
                Toggle::make('status')
                    ->label('Active')
                    ->default(true)
                    ->inline(false),
                Textarea::make('remarks')
                    ->label('Remarks')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);

        if ($columnSpan !== null) {
            $section->columnSpan($columnSpan);
        }

        return [$section];
    }

    /**
     * @param  array<string, int>|int|string|null  $columnSpan
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function openingStockComponents(
        bool $readOnly = false,
        array|int|string|null $columnSpan = null,
    ): array {
        // Create description kept concise so it wraps less in the ~40% side column.
        $description = $readOnly
            ? 'As entered at create. Opening stock is not changed on Edit (no duplicate Opening Stock ledger). Use Production Entry or Stock Adjustment for later inventory changes.'
            : 'Optional. Qty > 0 posts opening stock, updates finished inventory and average cost, and creates an Opening Stock ledger on Create. Leave 0 for no stock. Later output uses Production Entry.';

        $section = Section::make('Opening Stock')
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
                            : 'Select Unit in Product Details before entering quantity.'))
                    ->rules($readOnly ? [] : [
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $qty = (float) ($value ?? 0);
                            if ($qty < 0 || ! is_numeric($value ?? 0)) {
                                $fail('Opening Stock Quantity must be zero or greater.');
                            }
                            if ($qty > 0 && blank($get('unit'))) {
                                $fail('Select Unit before entering Opening Stock Quantity.');
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
            ]);

        if ($columnSpan !== null) {
            $section->columnSpan($columnSpan);
        }

        return [$section];
    }

    public static function configure(Schema $schema): Schema
    {
        return self::configureEdit($schema);
    }

    public static function configureCreate(Schema $schema): Schema
    {
        // Desktop ~60% / ~40% (3+2 of 5 cols); stack on smaller breakpoints.
        return $schema
            ->columns(1)
            ->components([
                Grid::make([
                    'default' => 1,
                    'lg' => 5,
                ])
                    ->columnSpanFull()
                    ->schema([
                        ...self::productDetailsComponents(
                            forEdit: false,
                            columnSpan: ['default' => 1, 'lg' => 3],
                        ),
                        ...self::openingStockComponents(
                            readOnly: false,
                            columnSpan: ['default' => 1, 'lg' => 2],
                        ),
                    ]),
            ]);
    }

    public static function configureEdit(Schema $schema, bool $unitLocked = false): Schema
    {
        return $schema->components([
            ...self::productDetailsComponents(forEdit: true, unitLocked: $unitLocked),
            ...self::openingStockComponents(readOnly: true),
        ]);
    }

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
