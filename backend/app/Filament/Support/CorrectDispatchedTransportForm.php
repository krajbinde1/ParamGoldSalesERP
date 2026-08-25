<?php

namespace App\Filament\Support;

use App\Enums\TransportChargeType;
use App\Models\Order;
use App\Models\Vehicle;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

final class CorrectDispatchedTransportForm
{
    /**
     * @return list<Component>
     */
    public static function schema(Order $order): array
    {
        return [
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
                ->content(fn (Get $get): HtmlString => SendForBillForm::renderPreview($order, $get)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromOrder(Order $order): array
    {
        return [
            'vehicle_id' => $order->vehicle_id,
            'transport_charge_type' => $order->transport_charge_type,
            'transport_freight' => $order->transport_amount,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function vehicleOptions(Order $order, ?string $search = null): array
    {
        return Vehicle::query()
            ->where(function ($query) use ($order): void {
                $query->where('is_active', true);
                if ($order->vehicle_id) {
                    $query->orWhereKey($order->vehicle_id);
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
}
