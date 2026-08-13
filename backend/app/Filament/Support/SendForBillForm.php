<?php

namespace App\Filament\Support;

use App\Models\Vehicle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SendForBillForm
{
    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Select::make('vehicle_id')
                ->label('Vehicle Number')
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
            TextInput::make('transport_freight')
                ->label('Transport Charges')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('₹')
                ->required(),
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
     * @param  array{vehicle_id?: int|string|null, transport_freight?: mixed, transport_remark?: ?string}  $data
     * @return array{vehicle: Vehicle, transport_freight: float, transport_remark: ?string}
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

        $freight = $data['transport_freight'] ?? null;
        if ($freight === null || $freight === '' || ! is_numeric($freight) || (float) $freight < 0) {
            throw ValidationException::withMessages([
                'transport_freight' => ['Enter a valid transport charge (minimum 0).'],
            ]);
        }

        return [
            'vehicle' => $vehicle,
            'transport_freight' => (float) $freight,
            'transport_remark' => filled($data['transport_remark'] ?? null)
                ? trim((string) $data['transport_remark'])
                : null,
        ];
    }
}
