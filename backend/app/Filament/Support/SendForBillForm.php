<?php

namespace App\Filament\Support;

use App\Enums\TransportChargeType;
use App\Models\Order;
use App\Models\Vehicle;
use App\Services\Orders\OrderBillingTransportCalculator;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SendForBillForm
{
    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function schema(Order $order): array
    {
        $originalGrandTotal = OrderBillingTransportCalculator::originalGrandTotal($order);

        return [
            Select::make('vehicle_id')
                ->label('Vehicle No')
                ->options(fn (): array => self::activeVehicleOptions())
                ->getSearchResultsUsing(fn (string $search): array => self::activeVehicleOptions($search))
                ->getOptionLabelUsing(function ($value): ?string {
                    if (blank($value)) {
                        return null;
                    }

                    return Vehicle::query()->find($value)?->displayLabel();
                })
                ->searchable()
                ->preload()
                ->required()
                ->createOptionForm([
                    TextInput::make('vehicle_number')
                        ->label('Vehicle Number')
                        ->required()
                        ->maxLength(50)
                        ->dehydrateStateUsing(fn (?string $state): string => Vehicle::normalizeVehicleNumber((string) $state)),
                    TextInput::make('vehicle_name')
                        ->label('Vehicle Name / Model')
                        ->maxLength(255),
                    TextInput::make('vehicle_type')
                        ->label('Vehicle Type')
                        ->maxLength(100),
                ])
                ->createOptionUsing(function (array $data): int {
                    return self::createVehicle($data)->id;
                })
                ->createOptionAction(fn ($action) => $action
                    ->label('+ Add Vehicle')
                    ->modalHeading('Add Vehicle')
                    ->modalSubmitActionLabel('Save Vehicle')),
            Radio::make('transport_charge_type')
                ->label('Transport Charge Type')
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
                ->content(fn (Get $get): HtmlString => self::renderPreview($originalGrandTotal, $get)),
            Textarea::make('transport_remark')
                ->label('Transport Remark (optional)')
                ->rows(2)
                ->maxLength(2000),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function activeVehicleOptions(?string $search = null): array
    {
        return Vehicle::query()
            ->active()
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

    /**
     * @param  array{vehicle_number?: string, vehicle_name?: ?string, vehicle_type?: ?string}  $data
     */
    public static function createVehicle(array $data): Vehicle
    {
        $normalized = Vehicle::normalizeVehicleNumber((string) ($data['vehicle_number'] ?? ''));

        Validator::make(
            [
                'vehicle_number' => $normalized,
                'vehicle_name' => $data['vehicle_name'] ?? null,
                'vehicle_type' => $data['vehicle_type'] ?? null,
            ],
            [
                'vehicle_number' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('vehicles', 'vehicle_number'),
                ],
                'vehicle_name' => ['nullable', 'string', 'max:255'],
                'vehicle_type' => ['nullable', 'string', 'max:100'],
            ],
            [
                'vehicle_number.unique' => 'This vehicle number already exists.',
            ],
        )->validate();

        return Vehicle::query()->create([
            'vehicle_number' => $normalized,
            'vehicle_name' => filled($data['vehicle_name'] ?? null) ? trim((string) $data['vehicle_name']) : null,
            'vehicle_type' => filled($data['vehicle_type'] ?? null) ? trim((string) $data['vehicle_type']) : null,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * @param  array{vehicle_id?: int|string|null, transport_charge_type?: ?string, transport_freight?: mixed, transport_remark?: ?string}  $data
     * @return array{vehicle: Vehicle, transport_charge_type: string, transport_freight: float, transport_remark: ?string}
     */
    public static function resolvePayload(array $data): array
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle = Vehicle::query()->active()->find($vehicleId);

        if ($vehicle === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['Select a valid active vehicle.'],
            ]);
        }

        $chargeType = (string) ($data['transport_charge_type'] ?? '');
        if (TransportChargeType::tryFrom($chargeType) === null) {
            throw ValidationException::withMessages([
                'transport_charge_type' => ['Select Company Transport or Transport Charges Extra.'],
            ]);
        }

        $freight = $data['transport_freight'] ?? null;
        if ($freight === null || $freight === '' || ! is_numeric($freight) || (float) $freight < 0) {
            throw ValidationException::withMessages([
                'transport_freight' => ['Enter a valid transport charge (minimum 0).'],
            ]);
        }

        return [
            'vehicle' => $vehicle,
            'transport_charge_type' => $chargeType,
            'transport_freight' => (float) $freight,
            'transport_remark' => filled($data['transport_remark'] ?? null)
                ? trim((string) $data['transport_remark'])
                : null,
        ];
    }

    public static function renderPreview(float $originalGrandTotal, Get $get): HtmlString
    {
        $type = (string) ($get('transport_charge_type') ?? '');
        $rawFreight = $get('transport_freight');
        $charges = is_numeric($rawFreight) ? (float) $rawFreight : 0.0;
        $typeLabel = TransportChargeType::tryFrom($type)?->label() ?? '—';
        $error = null;
        $adjustment = 0.0;
        $final = $originalGrandTotal;

        if ($type !== '' && is_numeric($rawFreight)) {
            try {
                $calc = OrderBillingTransportCalculator::calculate($originalGrandTotal, $type, $charges);
                $adjustment = $calc['transport_adjustment'];
                $final = $calc['final_grand_total'];
            } catch (ValidationException $exception) {
                $messages = $exception->errors();
                $error = collect($messages)->flatten()->first();
            }
        }

        $rows = [
            ['Original Grand Total', OrderBillingTransportCalculator::formatMoney($originalGrandTotal)],
            ['Transport Charges', OrderBillingTransportCalculator::formatMoney($charges)],
            ['Transport Type', $typeLabel],
            ['Adjustment', $type === '' ? '—' : OrderBillingTransportCalculator::formatAdjustment($adjustment)],
            ['Final Grand Total', OrderBillingTransportCalculator::formatMoney($final)],
        ];

        $html = '<div style="margin-top:4px;padding:12px 14px;border:1px solid #E2E8F0;border-radius:10px;background:#F8FAFC;">';
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
}
