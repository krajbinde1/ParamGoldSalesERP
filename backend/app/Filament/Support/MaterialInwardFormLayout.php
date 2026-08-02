<?php

namespace App\Filament\Support;

use App\Models\Supplier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Shared compact Material Inward form layout for Raw and Packaging.
 */
final class MaterialInwardFormLayout
{
    /**
     * @param  array<int, mixed>  $materialOptions
     * @param  callable(mixed, Set, Get): void  $hydrateMaterial
     */
    public static function schema(
        string $title,
        string $attachmentDirectory,
        string $materialField,
        string $materialLabel,
        array $materialOptions,
        callable $hydrateMaterial,
    ): array {
        return [
            Section::make($title)
                ->extraAttributes(['class' => 'paramgold-inward-compact'])
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                        'lg' => 6,
                    ])
                        ->extraAttributes(['class' => 'paramgold-inward-header-row'])
                        ->schema([
                            TextInput::make('inward_number')
                                ->label('Inward No.')
                                ->placeholder('Auto')
                                ->readOnly()
                                ->dehydrated(false),
                            DatePicker::make('inward_date')
                                ->label('Date')
                                ->native(false)
                                ->required()
                                ->default(fn () => now('Asia/Kolkata')->toDateString()),
                            Select::make('supplier_id')
                                ->label('Supplier')
                                ->relationship('supplier', 'supplier_name', fn (Builder $query) => $query->where('status', true))
                                ->searchable()
                                ->preload()
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
                                }),
                            TextInput::make('supplier_invoice_number')
                                ->label('Invoice No.')
                                ->required()
                                ->maxLength(100),
                            DatePicker::make('supplier_invoice_date')
                                ->label('Invoice Date')
                                ->native(false),
                            Placeholder::make('inward_form_summary')
                                ->hiddenLabel()
                                ->content(fn (Get $get): HtmlString => self::renderFormSummary($get('items') ?? [])),
                        ]),
                    Hidden::make('supplier_name'),
                ]),
            Section::make('Materials')
                ->extraAttributes(['class' => 'paramgold-inward-compact'])
                ->schema([
                    Repeater::make('items')
                        ->label('')
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('+ Add')
                        ->reorderable(false)
                        ->live()
                        ->extraAttributes(['class' => 'paramgold-inward-items'])
                        ->schema(self::itemSchema($materialField, $materialLabel, $materialOptions, $hydrateMaterial)),
                ]),
            Section::make()
                ->extraAttributes(['class' => 'paramgold-inward-compact paramgold-inward-footer'])
                ->schema([
                    Textarea::make('remarks')
                        ->label('Remarks')
                        ->rows(1)
                        ->extraInputAttributes(['style' => 'min-height:2rem;height:2rem;max-height:2.5rem;resize:vertical;'])
                        ->extraAttributes(['class' => 'paramgold-inward-remarks']),
                    FileUpload::make('attachment_path')
                        ->label('Invoice Attachment')
                        ->directory($attachmentDirectory)
                        ->panelLayout('compact')
                        ->imagePreviewHeight('40')
                        ->openable(false)
                        ->downloadable(false)
                        ->reorderable(false)
                        ->extraAttributes(['class' => 'paramgold-inward-attachment']),
                ]),
        ];
    }

    /**
     * @param  array<int, mixed>  $materialOptions
     * @param  callable(mixed, Set, Get): void  $hydrateMaterial
     * @return array<int, mixed>
     */
    private static function itemSchema(
        string $materialField,
        string $materialLabel,
        array $materialOptions,
        callable $hydrateMaterial,
    ): array {
        $recalc = fn (Get $get, Set $set) => self::recalculateLine($get, $set);

        return [
            Grid::make([
                'default' => 1,
                'md' => 2,
                'lg' => 7,
            ])
                ->extraAttributes(['class' => 'paramgold-inward-item-row'])
                ->schema([
                    Select::make($materialField)
                        ->label('Material')
                        ->options($materialOptions)
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) use ($hydrateMaterial, $recalc): void {
                            $hydrateMaterial($state, $set, $get);
                            $recalc($get, $set);
                        }),
                    TextInput::make('current_stock_display')
                        ->label('Stock Before')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('current_average_rate_display')
                        ->label('Old Avg. Rate')
                        ->prefix('₹')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('inward_quantity')
                        ->label('Qty')
                        ->numeric()
                        ->minValue(0.001)
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated($recalc),
                    TextInput::make('basic_rate')
                        ->label('Purchase Rate')
                        ->prefix('₹')
                        ->numeric()
                        ->minValue(0.0001)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated($recalc),
                    TextInput::make('gst_percentage')
                        ->label('GST %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated($recalc),
                    TextInput::make('total_amount_display')
                        ->label('Total')
                        ->prefix('₹')
                        ->disabled()
                        ->dehydrated(false),
                ]),
            Hidden::make('unit')->dehydrated(true),
            Hidden::make('current_average_rate')->dehydrated(false),
            Section::make('More Details')
                ->collapsed()
                ->extraAttributes(['class' => 'paramgold-inward-more-details'])
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                        'lg' => 5,
                    ])
                        ->extraAttributes(['class' => 'paramgold-inward-more-row'])
                        ->schema([
                            TextInput::make('discount_amount')
                                ->label('Discount')
                                ->prefix('₹')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated($recalc),
                            TextInput::make('freight_amount')
                                ->label('Freight')
                                ->prefix('₹')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated($recalc),
                            TextInput::make('other_charges')
                                ->label('Other Taxable Charges')
                                ->prefix('₹')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated($recalc),
                            TextInput::make('taxable_value_display')
                                ->label('Taxable Amount')
                                ->prefix('₹')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('gst_amount_display')
                                ->label('GST Amount')
                                ->prefix('₹')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('effective_inventory_value_display')
                                ->label('Effective Inventory Value')
                                ->prefix('₹')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('effective_unit_rate_display')
                                ->label('Effective Rate')
                                ->prefix('₹')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('expected_stock_after_display')
                                ->label('Expected Stock After')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('expected_average_rate_display')
                                ->label('Expected New Average Rate')
                                ->prefix('₹')
                                ->disabled()
                                ->dehydrated(false),
                            Textarea::make('remarks')
                                ->label('Item Remarks')
                                ->rows(1)
                                ->columnSpan([
                                    'default' => 1,
                                    'md' => 2,
                                    'lg' => 5,
                                ]),
                        ]),
                ]),
        ];
    }

    public static function recalculateLine(Get $get, Set $set): void
    {
        $amounts = self::computeLineAmounts($get);

        $set('effective_unit_rate_display', number_format($amounts['effective_rate'], 4, '.', ''));
        $set('total_amount_display', number_format($amounts['total_amount'], 2, '.', ''));
        $set('expected_average_rate_display', number_format($amounts['expected_average_rate'], 4, '.', ''));
        $set('basic_value_display', number_format($amounts['basic_value'], 2, '.', ''));
        $set('taxable_value_display', number_format($amounts['taxable_value'], 2, '.', ''));
        $set('gst_amount_display', number_format($amounts['gst_amount'], 2, '.', ''));
        $set('effective_inventory_value_display', number_format($amounts['effective_inventory_value'], 2, '.', ''));
        $set('expected_stock_after_display', number_format($amounts['current_stock'] + $amounts['qty'], 3, '.', ''));
    }

    /**
     * @return array{
     *     qty: float,
     *     purchase_rate: float,
     *     current_stock: float,
     *     current_average_rate: float,
     *     basic_value: float,
     *     discount: float,
     *     other_charges: float,
     *     taxable_value: float,
     *     freight: float,
     *     gst_amount: float,
     *     effective_inventory_value: float,
     *     total_amount: float,
     *     effective_rate: float,
     *     expected_average_rate: float
     * }
     */
    public static function computeLineAmounts(Get $get): array
    {
        $qty = (float) ($get('inward_quantity') ?? 0);
        $rate = (float) ($get('basic_rate') ?? 0);
        $discount = (float) ($get('discount_amount') ?? 0);
        $freight = (float) ($get('freight_amount') ?? 0);
        $other = (float) ($get('other_charges') ?? 0);
        $gstPct = (float) ($get('gst_percentage') ?? 0);
        $currentStock = (float) ($get('current_stock_display') ?? 0);
        $currentAvg = (float) ($get('current_average_rate') ?? $get('current_average_rate_display') ?? 0);

        $basic = round($qty * $rate, 2);
        // Taxable = Base − Discount + Other (freight NOT taxable).
        // Total = Taxable + GST (excludes freight). Effective = Total + Freight.
        $taxable = round($basic - $discount + $other, 2);
        $gst = round($taxable * $gstPct / 100, 2);
        $totalAmount = round($taxable + $gst, 2);
        $effectiveInventoryValue = round($totalAmount + $freight, 2);
        $effective = $qty > 0 ? round($effectiveInventoryValue / $qty, 4) : 0.0;

        $newStock = $currentStock + $qty;
        if ($qty <= 0) {
            $expectedAvg = $currentAvg;
        } elseif ($currentStock <= 0 || $newStock <= 0) {
            $expectedAvg = $effective;
        } else {
            $expectedAvg = round((($currentStock * $currentAvg) + ($qty * $effective)) / $newStock, 4);
        }

        return [
            'qty' => $qty,
            'purchase_rate' => $rate,
            'current_stock' => $currentStock,
            'current_average_rate' => $currentAvg,
            'basic_value' => $basic,
            'discount' => $discount,
            'other_charges' => $other,
            'taxable_value' => $taxable,
            'freight' => $freight,
            'gst_amount' => $gst,
            'effective_inventory_value' => $effectiveInventoryValue,
            'total_amount' => $totalAmount,
            'effective_rate' => $effective,
            'expected_average_rate' => $expectedAvg,
        ];
    }

    public static function renderLineCostBreakdown(Get $get): HtmlString
    {
        $a = self::computeLineAmounts($get);

        $rows = [
            'Basic Value' => '₹'.number_format($a['basic_value'], 2),
            'Discount' => '₹'.number_format($a['discount'], 2),
            'Freight Charges' => '₹'.number_format($a['freight'], 2),
            'Other Taxable Charges' => '₹'.number_format($a['other_charges'], 2),
            'Taxable Amount' => '₹'.number_format($a['taxable_value'], 2),
            'GST Amount' => '₹'.number_format($a['gst_amount'], 2),
            'Effective Inventory Value' => '₹'.number_format($a['effective_inventory_value'], 2),
            'Effective Rate' => '₹'.number_format($a['effective_rate'], 4),
            'Expected Stock After' => number_format($a['current_stock'] + $a['qty'], 3),
            'Expected New Average Rate' => '₹'.number_format($a['expected_average_rate'], 4),
        ];

        $html = '<div class="paramgold-inward-line-summary">';
        foreach ($rows as $label => $value) {
            $html .= '<div class="paramgold-inward-line-summary__row">'
                .'<span>'.e($label).'</span>'
                .'<strong>'.e($value).'</strong>'
                .'</div>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * @param  array<int|string, mixed>  $items
     */
    public static function renderFormSummary(array $items): HtmlString
    {
        $totalQty = 0.0;
        $grandTotal = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $qty = (float) ($item['inward_quantity'] ?? 0);
            $rate = (float) ($item['basic_rate'] ?? 0);
            $discount = (float) ($item['discount_amount'] ?? 0);
            $freight = (float) ($item['freight_amount'] ?? 0);
            $other = (float) ($item['other_charges'] ?? 0);
            $gstPct = (float) ($item['gst_percentage'] ?? 0);

            $basic = round($qty * $rate, 2);
            $taxable = round($basic - $discount + $other, 2);
            $gst = round($taxable * $gstPct / 100, 2);
            $effectiveInventoryValue = round($taxable + $gst + $freight, 2);

            $totalQty += $qty;
            $grandTotal += $effectiveInventoryValue;
        }

        $html = '<div class="paramgold-inward-side-summary">'
            .'<div class="paramgold-inward-side-summary__row"><span>Total Qty</span><strong>'.e(number_format($totalQty, 3)).'</strong></div>'
            .'<div class="paramgold-inward-side-summary__row is-total"><span>Total Value</span><strong>₹'.e(number_format($grandTotal, 2)).'</strong></div>'
            .'</div>';

        return new HtmlString($html);
    }

    public static function hydrateFromMaterial(
        Set $set,
        ?float $currentStock,
        ?string $unit,
        ?float $purchaseRate,
        ?float $averageRate,
    ): void {
        $avg = (float) ($averageRate ?? 0);
        $set('unit', $unit);
        $set('current_stock_display', number_format((float) ($currentStock ?? 0), 3, '.', ''));
        $set('current_average_rate', $avg);
        $set('current_average_rate_display', number_format($avg, 4, '.', ''));
        $set('basic_rate', $purchaseRate ?? 0);
    }
}
