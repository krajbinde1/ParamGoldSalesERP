<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\PurchaseFreightType;
use App\Enums\PurchaseMaterialType;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\PackagingMaterial;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Services\Inventory\PurchaseFreightAllocator;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Purchase details')
                    ->extraAttributes(['class' => 'paramgold-purchase-form'])
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 12,
                        ])
                            ->extraAttributes(['class' => 'paramgold-purchase-header-grid'])
                            ->schema([
                                TextInput::make('purchase_number')
                                    ->label('Purchase No.')
                                    ->placeholder('Generated automatically when saved')
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 3]),
                                DatePicker::make('purchase_date')
                                    ->label('Purchase Date')
                                    ->native(false)
                                    ->required()
                                    ->default(fn () => now('Asia/Kolkata')->toDateString())
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 3]),
                                Select::make('supplier_id')
                                    ->label('Supplier Name')
                                    ->relationship('supplier', 'supplier_name', fn (Builder $query) => $query->where('status', true))
                                    ->searchable()
                                    ->preload()
                                    ->wrapOptionLabels(false)
                                    ->required()
                                    ->createOptionForm([
                                        TextInput::make('supplier_name')->label('Supplier Name')->required()->maxLength(255),
                                        TextInput::make('phone')->tel()->maxLength(30),
                                        TextInput::make('gstin')->maxLength(20),
                                    ])
                                    ->createOptionUsing(function (array $data): int {
                                        return Supplier::query()->create([
                                            ...$data,
                                            'created_by' => auth()->id(),
                                        ])->id;
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        $set('supplier_name', Supplier::query()->find($state)?->supplier_name);
                                    })
                                    ->extraFieldWrapperAttributes(['class' => 'paramgold-purchase-dropdown'])
                                    ->columnSpan(['default' => 1, 'md' => 2, 'lg' => 6]),
                                TextInput::make('supplier_name')->hidden()->dehydrated(),
                                TextInput::make('supplier_invoice_number')
                                    ->label('Supplier Invoice No.')
                                    ->required()
                                    ->maxLength(80)
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                DatePicker::make('supplier_invoice_date')
                                    ->label('Supplier Invoice Date')
                                    ->native(false)
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                Select::make('material_type')
                                    ->label('Material Type')
                                    ->options(PurchaseMaterialType::options())
                                    ->wrapOptionLabels(false)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, mixed $old): void {
                                        if (filled($old) && $old !== $state) {
                                            $set('items', []);
                                        }
                                    })
                                    ->extraFieldWrapperAttributes(['class' => 'paramgold-purchase-dropdown'])
                                    ->columnSpan(['default' => 1, 'md' => 2, 'lg' => 4]),
                                Textarea::make('remarks')
                                    ->label('Remark')
                                    ->rows(2)
                                    ->columnSpan(['default' => 1, 'md' => 2, 'lg' => 12]),
                                FileUpload::make('invoice_path')
                                    ->label('Upload Purchase Invoice')
                                    ->directory('purchases/invoices')
                                    ->acceptedFileTypes([
                                        'application/pdf',
                                        'image/jpeg',
                                        'image/png',
                                    ])
                                    ->maxSize(10240)
                                    ->columnSpan(['default' => 1, 'md' => 2, 'lg' => 12]),
                            ]),
                    ]),
                Section::make('Purchase items')
                    ->extraAttributes(['class' => 'paramgold-purchase-form paramgold-purchase-items-section'])
                    ->schema([
                        Repeater::make('items')
                            ->label('')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Add material')
                            ->compact()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateFreightAllocation($set, $get))
                            ->extraAttributes(['class' => 'paramgold-purchase-items'])
                            ->schema([
                                Grid::make([
                                    'default' => 1,
                                    'md' => 2,
                                    'lg' => 12,
                                ])
                                    ->extraAttributes(['class' => 'paramgold-purchase-item-row paramgold-purchase-item-row--primary'])
                                    ->schema([
                                        Select::make('raw_material_id')
                                            ->label('Material Name')
                                            ->options(fn (): array => RawMaterial::query()
                                                ->where('status', true)
                                                ->orderBy('material_name')
                                                ->get()
                                                ->mapWithKeys(fn (RawMaterial $material): array => [
                                                    $material->id => trim($material->material_code.' — '.$material->material_name),
                                                ])
                                                ->all())
                                            ->searchable()
                                            ->preload()
                                            ->wrapOptionLabels(false)
                                            ->required(fn (Get $get): bool => $get('../../material_type') === PurchaseMaterialType::RawMaterial->value)
                                            ->visible(fn (Get $get): bool => $get('../../material_type') === PurchaseMaterialType::RawMaterial->value
                                                || blank($get('../../material_type')))
                                            ->live()
                                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                                $material = RawMaterial::query()->find($state);
                                                $set('unit', $material?->unit);
                                                $set('purchase_rate', $material?->purchase_rate ?: null);
                                                self::recalculateLine($set, $get);
                                                self::recalculateFreightAllocation($set, $get);
                                            })
                                            ->extraFieldWrapperAttributes(['class' => 'paramgold-purchase-material'])
                                            ->columnSpan(['default' => 1, 'md' => 2, 'lg' => 4]),
                                        Select::make('packaging_material_id')
                                            ->label('Material Name')
                                            ->options(fn (): array => PackagingMaterial::query()
                                                ->where('status', true)
                                                ->orderBy('packaging_name')
                                                ->get()
                                                ->mapWithKeys(fn (PackagingMaterial $material): array => [
                                                    $material->id => trim($material->packaging_code.' — '.$material->packaging_name),
                                                ])
                                                ->all())
                                            ->searchable()
                                            ->preload()
                                            ->wrapOptionLabels(false)
                                            ->required(fn (Get $get): bool => $get('../../material_type') === PurchaseMaterialType::PackingMaterial->value)
                                            ->visible(fn (Get $get): bool => $get('../../material_type') === PurchaseMaterialType::PackingMaterial->value)
                                            ->live()
                                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                                $material = PackagingMaterial::query()->find($state);
                                                $set('unit', $material?->unit);
                                                $set('purchase_rate', $material?->purchase_rate ?: null);
                                                self::recalculateLine($set, $get);
                                                self::recalculateFreightAllocation($set, $get);
                                            })
                                            ->extraFieldWrapperAttributes(['class' => 'paramgold-purchase-material'])
                                            ->columnSpan(['default' => 1, 'md' => 2, 'lg' => 4]),
                                        TextInput::make('quantity')
                                            ->label('Quantity')
                                            ->numeric()
                                            ->minValue(0.001)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                                self::recalculateLine($set, $get);
                                                self::recalculateFreightAllocation($set, $get);
                                            })
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 2]),
                                        TextInput::make('unit')
                                            ->label('UOM')
                                            ->readOnly()
                                            ->dehydrated()
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 2]),
                                        TextInput::make('purchase_rate')
                                            ->label('Purchase Rate')
                                            ->helperText('Ex GST. Matches the supplier invoice rate.')
                                            ->numeric()
                                            ->prefix('₹')
                                            ->minValue(0)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                                self::recalculateLine($set, $get);
                                                self::recalculateFreightAllocation($set, $get);
                                            })
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 2]),
                                        Select::make('gst_percentage')
                                            ->label('GST %')
                                            ->options(ProductForm::gstOptions())
                                            ->formatStateUsing(fn (mixed $state): ?string => ProductForm::normalizeGstKey($state))
                                            ->wrapOptionLabels(false)
                                            ->default('0')
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                                self::recalculateLine($set, $get);
                                                self::recalculateFreightAllocation($set, $get);
                                            })
                                            ->extraFieldWrapperAttributes(['class' => 'paramgold-purchase-dropdown'])
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 2]),
                                    ]),
                                Grid::make([
                                    'default' => 1,
                                    'md' => 2,
                                    'lg' => 12,
                                ])
                                    ->extraAttributes(['class' => 'paramgold-purchase-item-row paramgold-purchase-item-row--amounts'])
                                    ->schema([
                                        TextInput::make('taxable_amount')
                                            ->label('Taxable Amount')
                                            ->prefix('₹')
                                            ->readOnly()
                                            ->dehydrated()
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 2]),
                                        TextInput::make('gst_amount')
                                            ->label('GST Amount')
                                            ->prefix('₹')
                                            ->readOnly()
                                            ->dehydrated()
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 2]),
                                        TextInput::make('total_amount')
                                            ->label('Supplier Line Total')
                                            ->prefix('₹')
                                            ->readOnly()
                                            ->dehydrated()
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 2]),
                                        TextInput::make('allocated_transport_cost')
                                            ->label('Allocated Transport Cost')
                                            ->prefix('₹')
                                            ->readOnly()
                                            ->dehydrated()
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 3]),
                                        TextInput::make('effective_unit_rate')
                                            ->label('Effective/Landed Rate')
                                            ->prefix('₹')
                                            ->readOnly()
                                            ->dehydrated()
                                            ->helperText('(Taxable + Allocated Freight) ÷ Qty. Used for stock costing.')
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 3]),
                                    ]),
                                Grid::make([
                                    'default' => 1,
                                    'md' => 2,
                                    'lg' => 12,
                                ])
                                    ->extraAttributes(['class' => 'paramgold-purchase-item-row paramgold-purchase-item-row--meta'])
                                    ->schema([
                                        TextInput::make('batch_lot_no')
                                            ->label('Batch/Lot No.')
                                            ->maxLength(80)
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                        TextInput::make('remarks')
                                            ->label('Remark')
                                            ->maxLength(255)
                                            ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 8]),
                                    ]),
                            ]),
                    ]),
                Section::make('Transport / Freight')
                    ->description('Not added to the supplier purchase bill. Allocated to materials for stock costing only.')
                    ->extraAttributes(['class' => 'paramgold-purchase-form'])
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 12,
                        ])
                            ->extraAttributes(['class' => 'paramgold-purchase-header-grid'])
                            ->schema([
                                Select::make('freight_type')
                                    ->label('Freight Type')
                                    ->options(PurchaseFreightType::options())
                                    ->default(PurchaseFreightType::TotalFreight->value)
                                    ->required()
                                    ->live()
                                    ->wrapOptionLabels(false)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        self::recalculateFreightAllocation($set, $get);
                                    })
                                    ->extraFieldWrapperAttributes(['class' => 'paramgold-purchase-dropdown'])
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 3]),
                                TextInput::make('freight_rate_per_ton')
                                    ->label('Freight Rate Per Ton')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->suffix('/Ton')
                                    ->minValue(0)
                                    ->default(0)
                                    ->visible(fn (Get $get): bool => self::isFreightPerTon($get))
                                    ->required(fn (Get $get): bool => self::isFreightPerTon($get))
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        self::recalculateFreightAllocation($set, $get);
                                    })
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 3]),
                                Placeholder::make('total_quantity_tons_display')
                                    ->label('Total Quantity (Ton)')
                                    ->content(function (Get $get): string {
                                        $tons = app(PurchaseFreightAllocator::class)
                                            ->totalQuantityInTons(self::itemsState($get));

                                        return number_format($tons, 3, '.', ',').' Ton';
                                    })
                                    ->visible(fn (Get $get): bool => self::isFreightPerTon($get))
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 3]),
                                TextInput::make('transport_cost')
                                    ->label(fn (Get $get): string => self::isFreightPerTon($get)
                                        ? 'Total Freight Cost'
                                        : 'Transport/Freight Cost')
                                    ->helperText(fn (Get $get): ?string => self::isFreightPerTon($get)
                                        ? 'Freight Rate Per Ton × Total Quantity in Ton.'
                                        : null)
                                    ->numeric()
                                    ->prefix('₹')
                                    ->minValue(0)
                                    ->default(0)
                                    ->readOnly(fn (Get $get): bool => self::isFreightPerTon($get))
                                    ->dehydrated()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculateFreightAllocation($set, $get))
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 3]),
                                TextInput::make('transporter_name')
                                    ->label('Transporter Name')
                                    ->maxLength(255)
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                TextInput::make('transport_invoice_lr_no')
                                    ->label('Transport Invoice/LR No.')
                                    ->maxLength(80)
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                Textarea::make('transport_remark')
                                    ->label('Transport Remark')
                                    ->rows(2)
                                    ->columnSpan(['default' => 1, 'md' => 2, 'lg' => 12]),
                            ]),
                    ]),
                Section::make('Cost summary')
                    ->description('Supplier Bill Grand Total always matches the material invoice. Transport is shown separately.')
                    ->extraAttributes(['class' => 'paramgold-purchase-form paramgold-purchase-summary'])
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 12,
                        ])
                            ->extraAttributes(['class' => 'paramgold-purchase-header-grid'])
                            ->schema([
                                Placeholder::make('material_bill_taxable_display')
                                    ->label('Material Bill Taxable Amount')
                                    ->content(fn (Get $get): string => self::formatMoney(self::sumItems($get, 'taxable_amount')))
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                Placeholder::make('material_bill_gst_display')
                                    ->label('GST')
                                    ->content(fn (Get $get): string => self::formatMoney(self::sumItems($get, 'gst_amount')))
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                Placeholder::make('supplier_bill_grand_total_display')
                                    ->label('Supplier Bill Grand Total')
                                    ->content(fn (Get $get): string => self::formatMoney(self::sumItems($get, 'total_amount')))
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                Placeholder::make('transport_cost_display')
                                    ->label('Transport/Freight Cost')
                                    ->content(fn (Get $get): string => self::formatMoney((float) ($get('transport_cost') ?? 0)))
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                                Placeholder::make('total_landed_cost_display')
                                    ->label('Total Landed Cost')
                                    ->content(function (Get $get): string {
                                        $landed = round(self::sumItems($get, 'taxable_amount') + (float) ($get('transport_cost') ?? 0), 2);

                                        return self::formatMoney($landed);
                                    })
                                    ->columnSpan(['default' => 1, 'md' => 1, 'lg' => 4]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>|Get  $state
     */
    public static function recalculateLine(Set $set, array|Get $state): void
    {
        $qty = (float) (is_array($state) ? ($state['quantity'] ?? 0) : ($state('quantity') ?? 0));
        $rate = (float) (is_array($state) ? ($state['purchase_rate'] ?? 0) : ($state('purchase_rate') ?? 0));
        $gst = (float) (is_array($state) ? ($state['gst_percentage'] ?? 0) : ($state('gst_percentage') ?? 0));

        $taxable = round($qty * $rate, 2);
        $gstAmount = round($taxable * $gst / 100, 2);
        $total = round($taxable + $gstAmount, 2);

        $set('taxable_amount', number_format($taxable, 2, '.', ''));
        $set('gst_amount', number_format($gstAmount, 2, '.', ''));
        $set('total_amount', number_format($total, 2, '.', ''));
    }

    public static function recalculateFreightAllocation(Set $set, Get $get): void
    {
        $insideItem = is_array($get('../../items'));
        $items = $insideItem ? $get('../../items') : $get('items');
        if (! is_array($items) || $items === []) {
            return;
        }

        $freightType = PurchaseFreightType::fromMixed(
            $insideItem ? $get('../../freight_type') : $get('freight_type')
        );
        $allocator = app(PurchaseFreightAllocator::class);

        if ($freightType === PurchaseFreightType::FreightPerTon) {
            $ratePerTon = (float) ($insideItem ? ($get('../../freight_rate_per_ton') ?? 0) : ($get('freight_rate_per_ton') ?? 0));
            $freight = $allocator->totalFreightFromPerTonRate($ratePerTon, array_values($items));
            $set($insideItem ? '../../transport_cost' : 'transport_cost', number_format($freight, 2, '.', ''));
        } else {
            $freight = (float) ($insideItem ? ($get('../../transport_cost') ?? 0) : ($get('transport_cost') ?? 0));
        }

        $taxables = [];
        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $rate = (float) ($item['purchase_rate'] ?? 0);
            $taxables[] = round($qty * $rate, 2);
        }

        $allocated = $allocator->allocate($freight, $taxables);
        $prefix = $insideItem ? '../../items' : 'items';
        $index = 0;

        foreach (array_keys($items) as $key) {
            $qty = (float) ($items[$key]['quantity'] ?? 0);
            $taxable = $taxables[$index] ?? 0.0;
            $alloc = (float) ($allocated[$index] ?? 0);
            $landedRate = $allocator->effectiveLandedRate($qty, $taxable, $alloc);

            $set("{$prefix}.{$key}.allocated_transport_cost", number_format($alloc, 2, '.', ''));
            $set("{$prefix}.{$key}.effective_unit_rate", number_format($landedRate, 4, '.', ''));
            $index++;
        }
    }

    private static function isFreightPerTon(Get $get): bool
    {
        return PurchaseFreightType::fromMixed($get('freight_type')) === PurchaseFreightType::FreightPerTon;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function itemsState(Get $get): array
    {
        $items = $get('items');

        return is_array($items) ? array_values($items) : [];
    }

    private static function sumItems(Get $get, string $field): float
    {
        $items = $get('items');
        if (! is_array($items)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($items as $item) {
            $sum += (float) ($item[$field] ?? 0);
        }

        return round($sum, 2);
    }

    private static function formatMoney(float $amount): string
    {
        return '₹'.number_format($amount, 2, '.', ',');
    }
}
