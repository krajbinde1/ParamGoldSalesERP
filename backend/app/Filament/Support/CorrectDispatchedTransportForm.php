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
                ->content(function (...$args) use ($order): HtmlString {
                    foreach ($args as $arg) {
                        if (! $arg instanceof Get) {
                            continue;
                        }

                        try {
                            return SendForBillForm::renderPreview($order, $arg);
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
        $vehicleId = self::resolveCurrentVehicleId($order);
        $chargeType = TransportChargeType::tryNormalize(
            filled($order->transport_charge_type) ? (string) $order->transport_charge_type : null
        ) ?? TransportChargeType::tryNormalize(
            filled($order->transport_type) ? (string) $order->transport_type : null
        );

        $freight = $order->transport_amount;

        return [
            'vehicle_id' => $vehicleId,
            'transport_charge_type' => $chargeType?->value,
            'transport_freight' => is_numeric($freight) ? round((float) $freight, 2) : null,
        ];
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
