<?php

namespace App\Filament\Support;

use App\Enums\TransportChargeType;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vehicle;
use App\Services\Orders\OrderBillingTransportCalculator;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

final class CorrectDispatchedTransportForm
{
    /**
     * @return list<Component>
     */
    public static function schema(Order $order): array
    {
        return [
            Repeater::make('items')
                ->label('Products')
                ->defaultItems(0)
                ->minItems($order->items()->exists() ? 1 : 0)
                ->live()
                ->schema([
                    Select::make('product_id')
                        ->label('Product')
                        ->options(fn (): array => self::productOptions())
                        ->getSearchResultsUsing(fn (string $search): array => self::productOptions($search))
                        ->getOptionLabelUsing(function ($value): ?string {
                            if (blank($value)) {
                                return null;
                            }

                            return Product::query()->find($value)?->product_name;
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, $set): void {
                            $product = Product::query()->find($state);
                            $set('nos_per_case', $product?->nos_per_case ?? 1);
                            $set('rate_per_no', $product?->dealer_price ?? 0);
                            $set('gst_percentage', $product?->gst_percentage ?? 0);
                            $set('rate_type', 'price_list');
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
                        ->label('Rate')
                        ->prefix('₹')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->live(),
                    TextInput::make('discount_percentage')
                        ->label('Discount %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->live(),
                    Hidden::make('gst_percentage'),
                    Hidden::make('rate_type'),
                ])
                ->columns(5)
                ->addActionLabel('Add product')
                ->columnSpanFull(),
            Select::make('vehicle_id')
                ->label('Vehicle No.')
                ->options(fn (): array => self::vehicleOptions($order))
                ->getSearchResultsUsing(fn (string $search): array => self::vehicleOptions($order, $search))
                ->getOptionLabelUsing(function ($value): ?string {
                    if (blank($value)) {
                        return null;
                    }

                    return Vehicle::query()->find($value)?->displayLabel();
                })
                ->searchable()
                ->preload()
                ->required(),
            Radio::make('transport_charge_type')
                ->label('Transport Type')
                ->options(TransportChargeType::options())
                ->required()
                ->live(),
            TextInput::make('transport_freight')
                ->label('Transport Charges')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('₹')
                ->required()
                ->live(debounce: 300),
            Placeholder::make('transport_preview')
                ->hiddenLabel()
                ->content(function (...$args) use ($order): HtmlString {
                    foreach ($args as $arg) {
                        if (! $arg instanceof Get) {
                            continue;
                        }

                        try {
                            return self::renderPreview($order, $arg);
                        } catch (\Throwable) {
                            return new HtmlString('');
                        }
                    }

                    return new HtmlString('');
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromOrder(Order $order): array
    {
        $order->loadMissing(['items.product:id,product_name,nos_per_case,dealer_price,gst_percentage']);
        $vehicleId = self::resolveCurrentVehicleId($order);
        $chargeType = TransportChargeType::tryNormalize(
            filled($order->transport_charge_type) ? (string) $order->transport_charge_type : null
        ) ?? TransportChargeType::tryNormalize(
            filled($order->transport_type) ? (string) $order->transport_type : null
        );

        $freight = $order->transport_amount;

        return [
            'items' => $order->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'case_quantity' => (int) ($item->case_quantity ?? 1),
                'nos_per_case' => (int) ($item->nos_per_case ?? $item->product?->nos_per_case ?? 1),
                'rate_per_no' => round((float) ($item->rate_per_no ?? $item->rate ?? 0), 2),
                'discount_percentage' => round((float) ($item->discount_percentage ?? 0), 2),
                'gst_percentage' => round((float) ($item->gst_percentage ?? $item->product?->gst_percentage ?? 0), 2),
                'rate_type' => (string) ($item->rate_type ?? 'price_list'),
            ])->values()->all(),
            'vehicle_id' => $vehicleId,
            'transport_charge_type' => $chargeType?->value,
            'transport_freight' => is_numeric($freight) ? round((float) $freight, 2) : null,
        ];
    }

    public static function renderPreview(Order $order, Get $get): HtmlString
    {
        $type = (string) ($get('transport_charge_type') ?? '');
        $rawFreight = $get('transport_freight');
        $charges = is_numeric($rawFreight) ? (float) $rawFreight : 0.0;
        $typeLabel = TransportChargeType::tryFrom($type)?->label() ?? '—';
        $error = null;
        $itemRows = array_values(array_filter(
            is_array($get('items')) ? $get('items') : [],
            fn (mixed $row): bool => is_array($row) && (int) ($row['product_id'] ?? 0) > 0,
        ));
        $base = $itemRows !== []
            ? OrderBillingTransportCalculator::resolveBaseTotalsFromLines($itemRows)
            : OrderBillingTransportCalculator::resolveBaseTotals($order);
        $adjustment = 0.0;
        $taxable = $base['taxable_amount'];
        $gst = $base['gst_amount'];
        $round = OrderBillingTransportCalculator::roundOffGrandTotal($base['grand_total']);
        $roundOff = $round['round_off'];
        $final = $round['rounded_grand_total'];

        if ($type !== '' && is_numeric($rawFreight)) {
            try {
                $calc = $itemRows !== []
                    ? OrderBillingTransportCalculator::calculateForLines($itemRows, $type, $charges, strict: false)
                    : OrderBillingTransportCalculator::calculateForOrder($order, $type, $charges, strict: false);
                $adjustment = $calc['transport_adjustment'];
                $taxable = $calc['taxable_amount_after_transport'];
                $gst = $calc['gst_amount'];
                $roundOff = $calc['round_off'];
                $final = $calc['final_grand_total'];
            } catch (ValidationException $exception) {
                $messages = $exception->errors();
                $error = collect($messages)->flatten()->first();
            }
        }

        $rows = [
            ['Subtotal', OrderBillingTransportCalculator::formatMoney($base['subtotal'])],
            ['Discount', OrderBillingTransportCalculator::formatMoney($base['discount_amount'])],
            ['Transport Type', $typeLabel],
            ['Transport Charges', $type === '' ? '—' : OrderBillingTransportCalculator::formatAdjustment($adjustment)],
            ['Taxable Value', OrderBillingTransportCalculator::formatMoney($taxable)],
            ['GST', OrderBillingTransportCalculator::formatMoney($gst)],
            ['Round Off', OrderBillingTransportCalculator::formatRoundOff($roundOff)],
            ['Grand Total', OrderBillingTransportCalculator::formatMoney($final)],
        ];

        $html = '<div style="margin-top:4px;padding:12px 14px;border:1px solid #E2E8F0;border-radius:10px;background:#F8FAFC;">';
        $html .= '<p style="margin:0 0 8px;font-size:12px;color:#475569;">ERP correction to match the existing Tally bill. Tally is not changed and a duplicate Tally Sales voucher is not created.</p>';
        foreach ($rows as $index => $row) {
            $isFinal = $index === count($rows) - 1;
            $html .= '<div style="display:flex;justify-content:space-between;gap:16px;padding:'.($isFinal ? '10px 0 0' : '4px 0').';'.($isFinal ? 'border-top:1px solid #E2E8F0;margin-top:6px;' : '').'">';
            $html .= '<span style="font-size:13px;color:#64748B;font-weight:'.($isFinal ? '700' : '500').';">'.e($row[0]).'</span>';
            $html .= '<span style="font-size:'.($isFinal ? '16px' : '13px').';color:'.($isFinal ? '#0F766E' : '#0F172A').';font-weight:'.($isFinal ? '800' : '600').';">'.e($row[1]).'</span>';
            $html .= '</div>';
        }
        if (filled($error)) {
            $html .= '<div style="margin-top:8px;font-size:13px;color:#B91C1C;font-weight:600;">'.e((string) $error).'</div>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * @return array<int, string>
     */
    private static function productOptions(?string $search = null): array
    {
        return Product::query()
            ->where('status', true)
            ->when(
                filled($search),
                fn ($query) => $query->where(function ($inner) use ($search): void {
                    $term = '%'.trim((string) $search).'%';
                    $inner->where('product_name', 'like', $term)
                        ->orWhere('product_code', 'like', $term);
                }),
            )
            ->orderBy('product_name')
            ->limit(50)
            ->pluck('product_name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function vehicleOptions(Order $order, ?string $search = null): array
    {
        $currentId = self::resolveCurrentVehicleId($order);
        $currentNumber = filled($order->vehicle_number)
            ? Vehicle::normalizeVehicleNumber((string) $order->vehicle_number)
            : null;

        return Vehicle::query()
            ->where(function ($query) use ($currentId, $currentNumber): void {
                $query->where('is_active', true);
                if ($currentId !== null) {
                    $query->orWhere('vehicles.id', $currentId);
                }
                if (filled($currentNumber)) {
                    $query->orWhere('vehicle_number', $currentNumber);
                }
            })
            ->when(
                filled($search),
                fn ($q) => $q->where(function ($inner) use ($search): void {
                    $term = '%'.trim((string) $search).'%';
                    $inner->where('vehicle_number', 'like', $term)
                        ->orWhere('vehicle_name', 'like', $term)
                        ->orWhere('vehicle_type', 'like', $term);
                }),
            )
            ->orderBy('vehicle_number')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Vehicle $vehicle): array => [
                $vehicle->id => $vehicle->displayLabel(),
            ])
            ->all();
    }

    private static function resolveCurrentVehicleId(Order $order): ?int
    {
        if (filled($order->vehicle_id) && (int) $order->vehicle_id > 0) {
            return (int) $order->vehicle_id;
        }

        if (! filled($order->vehicle_number)) {
            return null;
        }

        $matchedId = Vehicle::query()
            ->where('vehicle_number', Vehicle::normalizeVehicleNumber((string) $order->vehicle_number))
            ->value('id');

        return filled($matchedId) ? (int) $matchedId : null;
    }
}
