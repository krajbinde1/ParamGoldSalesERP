<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Support\DealerSelect;
use App\Models\Order;
use App\Models\Product;
use App\Filament\Support\EmployeeSelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order header')
                    ->columns(2)
                    ->schema([
                        TextInput::make('order_no')->label('Order No.')->placeholder('Generated automatically when saved')->readOnly()->dehydrated(false),
                        DatePicker::make('order_date')->default(fn () => Order::businessToday())->native(false)->required(),
                        Select::make('dealer_id')
                            ->label('Dealer')
                            ->tap(fn (Select $select) => DealerSelect::applyRelationshipSelect($select))
                            ->searchable()->preload()->required(),
                        Select::make('sales_employee_id')
                            ->label('Sales Employee')
                            ->relationship('salesEmployee', 'full_name', fn (Builder $query) => $query->where('status', true))
                            ->searchable()->preload()
                            ->tap(fn (Select $select) => EmployeeSelect::applyRelationshipSelect($select)),
                        Select::make('payment_type')->options(['Cash' => 'Cash', 'Credit' => 'Credit'])->default('Credit')->required(),
                        Select::make('status')->options(Order::statusLabels())->default('draft')->disabled()->dehydrated(),
                        Textarea::make('remarks')->rows(2)->columnSpanFull(),
                    ]),
                Section::make('Order items')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->defaultItems(1)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setOrderTotals($get, $set))
                            ->schema([
                                Select::make('product_id')
                                    ->label('Product')
                                    ->relationship('product', 'product_name', fn (Builder $query) => $query->where('status', true))
                                    ->searchable()->preload()->required()->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        $product = Product::find($state);
                                        $set('unit', $product?->uom);
                                        $set('nos_per_case', $product?->nos_per_case ?? 1);
                                        $set('rate_per_no', $product?->dealer_price ?? 0);
                                        $set('rate', $product?->dealer_price ?? 0);
                                        $set('gst_percentage', $product?->gst_percentage ?? 0);
                                    }),
                                TextInput::make('case_quantity')
                                    ->label('Cases')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(),
                                TextInput::make('nos_per_case')
                                    ->label('Nos Per Case')
                                    ->numeric()
                                    ->readOnly()
                                    ->dehydrated(),
                                TextInput::make('rate_per_no')
                                    ->label('Rate Per No')
                                    ->prefix('₹')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->required()
                                    ->live(),
                                TextInput::make('quantity')->hidden()->dehydrated(),
                                TextInput::make('unit')->required()->maxLength(30)->hidden(),
                                TextInput::make('rate')->hidden()->dehydrated(),
                                TextInput::make('discount_percentage')->label('Discount %')->numeric()->minValue(0)->maxValue(100)->default(0)->live(),
                                TextInput::make('gst_percentage')->label('GST %')->numeric()->minValue(0)->maxValue(100)->default(0)->live(),
                                TextInput::make('line_total')->label('Line Total')->prefix('₹')->numeric()->readOnly()->dehydrated(),
                            ])
                            ->columns(4)
                            ->addActionLabel('Add product'),
                    ]),
                Section::make('Order totals')
                    ->columns(4)
                    ->schema([
                        TextInput::make('subtotal')->label('Sub Total')->prefix('₹')->readOnly()->dehydrated()->default(0),
                        TextInput::make('discount_amount')->label('Discount')->prefix('₹')->readOnly()->dehydrated()->default(0),
                        TextInput::make('gst_amount')->label('GST')->prefix('₹')->readOnly()->dehydrated()->default(0),
                        TextInput::make('grand_total')->label('Grand Total')->prefix('₹')->readOnly()->dehydrated()->default(0),
                    ]),
            ]);
    }

    private static function setOrderTotals(Get $get, Set $set): void
    {
        $totals = ['subtotal' => 0.0, 'discount_amount' => 0.0, 'gst_amount' => 0.0, 'grand_total' => 0.0];

        foreach ($get('items') ?? [] as $item) {
            $caseQuantity = (float) ($item['case_quantity'] ?? 1);
            $nosPerCase = (float) ($item['nos_per_case'] ?? 1);
            $quantity = $caseQuantity * $nosPerCase;
            $rate = (float) ($item['rate_per_no'] ?? $item['rate'] ?? 0);
            $base = $quantity * $rate;
            $discount = $base * ((float) ($item['discount_percentage'] ?? 0) / 100);
            $taxable = $base - $discount;
            $gst = $taxable * ((float) ($item['gst_percentage'] ?? 0) / 100);

            $totals['subtotal'] += $base;
            $totals['discount_amount'] += $discount;
            $totals['gst_amount'] += $gst;
            $totals['grand_total'] += $taxable + $gst;
        }

        foreach ($totals as $field => $amount) {
            $set($field, round($amount, 2));
        }
    }
}
