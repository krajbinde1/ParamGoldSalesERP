<?php

namespace App\Filament\Resources\Boms\Schemas;

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\InventoryUnit;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Services\Inventory\BOMCalculationService;
use App\Services\Inventory\InventoryUnitConversion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class BomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('BOM details')
                    ->schema([
                        TextInput::make('bom_number')
                            ->label('BOM Number')
                            ->placeholder('Generated automatically when saved')
                            ->readOnly()
                            ->dehydrated(false),
                        Select::make('output_type')
                            ->label('Output Type')
                            ->options(BomOutputType::options())
                            ->default(BomOutputType::FinishedProduct->value)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('product_id', null);
                                $set('semi_finished_id', null);
                            }),
                        Select::make('product_id')
                            ->label('Output Item (Sales Product)')
                            ->helperText('Select a product from Sales Operations → Products.')
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'product_name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('status', true)
                                    ->orderBy('product_name'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (Product $record): string => (string) ($record->product_name ?? $record->name ?? '')
                            )
                            ->searchable(['product_name', 'product_code'])
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('output_type') === BomOutputType::FinishedProduct->value
                                || blank($get('output_type')))
                            ->required(fn (Get $get): bool => $get('output_type') === BomOutputType::FinishedProduct->value
                                || blank($get('output_type'))),
                        Select::make('semi_finished_id')
                            ->label('Output Item (Semi-Finished)')
                            ->relationship(
                                name: 'semiFinished',
                                titleAttribute: 'material_name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('status', true)
                                    ->orderBy('material_name'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (SemiFinishedMaterial $record): string => trim($record->material_code.' — '.$record->material_name)
                            )
                            ->searchable(['material_name', 'material_code'])
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('output_type') === BomOutputType::SemiFinished->value)
                            ->required(fn (Get $get): bool => $get('output_type') === BomOutputType::SemiFinished->value),
                        Select::make('status')
                            ->options(BomStatus::options())
                            ->default(BomStatus::Active->value)
                            ->required()
                            ->live(),
                        DatePicker::make('effective_date')
                            ->native(false)
                            ->default(now())
                            ->required(),
                        TextInput::make('batch_quantity')
                            ->label('Formula For Quantity')
                            ->numeric()
                            ->minValue(0.0001)
                            ->required()
                            ->live()
                            ->helperText('Number of output units this BOM is defined for (e.g. 100 Nos).'),
                        Select::make('batch_unit')
                            ->label('Batch Unit')
                            ->options(InventoryUnit::batchUnitOptions())
                            ->default(InventoryUnit::Nos->value)
                            ->required()
                            ->live(),
                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('BOM items')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->reorderable()
                            ->orderColumn('sort_order')
                            ->defaultItems(1)
                            ->addActionLabel('Add material')
                            ->live()
                            ->columns(3)
                            ->schema([
                                Select::make('item_type')
                                    ->label('Item Type')
                                    ->options(BomItemType::options())
                                    ->required()
                                    ->live()
                                    ->default(BomItemType::RawMaterial->value)
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('raw_material_id', null);
                                        $set('packaging_material_id', null);
                                        $set('semi_finished_id', null);
                                        $set('inventory_unit', null);
                                        $set('unit', null);
                                    }),
                                Select::make('raw_material_id')
                                    ->label('Material')
                                    ->relationship('rawMaterial', 'material_name', fn (Builder $query) => $query->where('status', true))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->visible(fn (Get $get): bool => $get('item_type') === BomItemType::RawMaterial->value)
                                    ->required(fn (Get $get): bool => $get('item_type') === BomItemType::RawMaterial->value)
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        $material = RawMaterial::query()->find($state);
                                        $inventoryUnit = $material?->unit;
                                        $set('inventory_unit', $inventoryUnit);
                                        $set('unit', self::defaultFormulationUnit($inventoryUnit));
                                    }),
                                Select::make('packaging_material_id')
                                    ->label('Material')
                                    ->relationship('packagingMaterial', 'packaging_name', fn (Builder $query) => $query->where('status', true))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->visible(fn (Get $get): bool => $get('item_type') === BomItemType::PackagingMaterial->value)
                                    ->required(fn (Get $get): bool => $get('item_type') === BomItemType::PackagingMaterial->value)
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        $material = PackagingMaterial::query()->find($state);
                                        $inventoryUnit = $material?->unit;
                                        $set('inventory_unit', $inventoryUnit);
                                        $set('unit', self::defaultFormulationUnit($inventoryUnit));
                                    }),
                                Select::make('semi_finished_id')
                                    ->label('Material')
                                    ->relationship('semiFinished', 'material_name', fn (Builder $query) => $query->where('status', true))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->visible(fn (Get $get): bool => $get('item_type') === BomItemType::SemiFinished->value)
                                    ->required(fn (Get $get): bool => $get('item_type') === BomItemType::SemiFinished->value)
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        $material = SemiFinishedMaterial::query()->find($state);
                                        $inventoryUnit = $material?->unit;
                                        $set('inventory_unit', $inventoryUnit);
                                        $set('unit', self::defaultFormulationUnit($inventoryUnit));
                                    }),
                                TextInput::make('inventory_unit')
                                    ->label('Inventory Unit')
                                    ->readOnly()
                                    ->dehydrated()
                                    ->helperText('Material stock unit'),
                                Select::make('unit')
                                    ->label('Formulation Unit')
                                    ->options(fn (Get $get): array => self::formulationUnitOptions($get('inventory_unit')))
                                    ->required()
                                    ->live(),
                                TextInput::make('required_quantity')
                                    ->label('Required Quantity')
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required()
                                    ->live(debounce: 300),
                                Placeholder::make('inventory_equivalent_preview')
                                    ->label('Inventory Deduction Equivalent')
                                    ->content(fn (Get $get): HtmlString => self::renderEquivalentPreview($get)),
                                Textarea::make('remarks')
                                    ->rows(1)
                                    ->columnSpanFull(),
                                Hidden::make('is_optional')->default(false),
                                Hidden::make('wastage_percentage')->default(0),
                                Hidden::make('inventory_equivalent_quantity')->dehydrated(),
                                Hidden::make('conversion_factor')->dehydrated(),
                            ]),
                    ]),
                Section::make('BOM Summary')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('bom_summary')
                            ->hiddenLabel()
                            ->content(fn (Get $get): HtmlString => self::renderBomSummary($get)),
                    ]),
            ]);
    }

    /**
     * Prefer Kg for Ton stock, Gram for Kg stock; otherwise inventory unit.
     */
    public static function defaultFormulationUnit(?string $inventoryUnit): ?string
    {
        if ($inventoryUnit === null || $inventoryUnit === '') {
            return null;
        }

        $normalized = app(InventoryUnitConversion::class)->normalize($inventoryUnit);

        return match ($normalized) {
            InventoryUnit::Ton->value => InventoryUnit::Kg->value,
            InventoryUnit::Kg->value => InventoryUnit::Kg->value,
            InventoryUnit::Litre->value => InventoryUnit::Litre->value,
            default => $normalized,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function formulationUnitOptions(?string $inventoryUnit): array
    {
        if ($inventoryUnit === null || $inventoryUnit === '') {
            return InventoryUnit::options();
        }

        $options = app(InventoryUnitConversion::class)->compatibleFormulationOptions($inventoryUnit);

        return $options !== [] ? $options : InventoryUnit::options();
    }

    public static function renderEquivalentPreview(Get $get): HtmlString
    {
        $qty = (float) ($get('required_quantity') ?? 0);
        $from = (string) ($get('unit') ?? '');
        $to = (string) ($get('inventory_unit') ?? '');

        if ($qty <= 0 || $from === '' || $to === '') {
            return new HtmlString('<span style="opacity:0.7;">—</span>');
        }

        try {
            $converted = app(InventoryUnitConversion::class)->convert($qty, $from, $to);
            $text = number_format((float) $converted['quantity'], 3).' '.$converted['to_unit'];

            return new HtmlString('<span class="tabular-nums font-semibold">'.e($text).'</span>');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Incompatible units';

            return new HtmlString('<span style="color:#b91c1c;">'.e((string) $message).'</span>');
        }
    }

    public static function renderBomSummary(Get $get): HtmlString
    {
        $summary = app(BOMCalculationService::class)->summarizeBom(
            [
                'batch_quantity' => $get('batch_quantity'),
                'batch_unit' => $get('batch_unit'),
            ],
            is_array($get('items')) ? $get('items') : [],
        );

        $showCosts = auth()->user()?->canViewProductionCosts() ?? false;

        $html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem 1.25rem;">'
            .'<div><div style="font-size:0.75rem;opacity:0.7;">Formula For</div><div style="font-weight:600;">'
            .e((string) $summary['formula_for_label']).'</div></div>'
            .'<div><div style="font-size:0.75rem;opacity:0.7;">Formula Quantity</div><div style="font-weight:600;">'
            .e(number_format((float) $summary['formula_quantity'], 3)).'</div></div>'
            .'<div><div style="font-size:0.75rem;opacity:0.7;">Formula Unit</div><div style="font-weight:600;">'
            .e((string) ($summary['formula_unit'] ?: '—')).'</div></div>'
            .'<div><div style="font-size:0.75rem;opacity:0.7;">Total Items</div><div style="font-weight:600;">'
            .e((string) $summary['total_items']).'</div></div>'
            .'<div><div style="font-size:0.75rem;opacity:0.7;">Raw Material Items</div><div style="font-weight:600;">'
            .e((string) $summary['raw_material_items']).'</div></div>'
            .'<div><div style="font-size:0.75rem;opacity:0.7;">Packaging Material Items</div><div style="font-weight:600;">'
            .e((string) $summary['packaging_material_items']).'</div></div>'
            .'<div><div style="font-size:0.75rem;opacity:0.7;">Semi-Finished Items</div><div style="font-weight:600;">'
            .e((string) ($summary['semi_finished_items'] ?? 0)).'</div></div>';

        if ($showCosts) {
            $html .= '<div><div style="font-size:0.75rem;opacity:0.7;">Estimated Raw Material Cost</div><div style="font-weight:600;">'
                .'₹'.e(number_format((float) $summary['estimated_raw_material_cost'], 2)).'</div></div>'
                .'<div><div style="font-size:0.75rem;opacity:0.7;">Estimated Packaging Cost</div><div style="font-weight:600;">'
                .'₹'.e(number_format((float) $summary['estimated_packaging_cost'], 2)).'</div></div>'
                .'<div><div style="font-size:0.75rem;opacity:0.7;">Estimated Semi-Finished Cost</div><div style="font-weight:600;">'
                .'₹'.e(number_format((float) ($summary['estimated_semi_finished_cost'] ?? 0), 2)).'</div></div>'
                .'<div><div style="font-size:0.75rem;opacity:0.7;">Estimated Total BOM Cost</div><div style="font-weight:600;">'
                .'₹'.e(number_format((float) $summary['estimated_total_bom_cost'], 2)).'</div></div>'
                .'<div><div style="font-size:0.75rem;opacity:0.7;">Estimated Cost Per Unit</div><div style="font-weight:600;">'
                .($summary['estimated_cost_per_finished_unit'] !== null
                    ? '₹'.e(number_format((float) $summary['estimated_cost_per_finished_unit'], 2))
                    : '—')
                .'</div></div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }
}
