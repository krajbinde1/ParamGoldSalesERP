<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SendOrderForBilling
{
    /**
     * Production Supervisor prepares transport details and sends order for Admin billing.
     *
     * @return array{order: Order}
     */
    public function execute(
        Order $order,
        User $actor,
        ?string $vehicleNumber = null,
        float $transportFreight = 0,
        ?string $transportRemark = null,
        ?int $vehicleId = null,
        ?string $transportChargeType = null,
    ): array {
        if (! Gate::forUser($actor)->allows('sendForBill', $order)) {
            throw new AuthorizationException('You are not allowed to send this order for billing.');
        }

        if (! $order->canBeSentForBilling()) {
            throw ValidationException::withMessages([
                'status' => 'Only orders approved by Sales Manager can be sent for billing.',
            ]);
        }

        $resolved = $this->resolveVehicle($vehicleId, $vehicleNumber);

        Validator::make(
            [
                'vehicle_id' => $resolved['vehicle_id'],
                'vehicle_number' => $resolved['vehicle_number'],
                'transport_charge_type' => $transportChargeType,
                'transport_freight' => $transportFreight,
                'transport_remark' => $transportRemark,
            ],
            [
                'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where('is_active', true)],
                'vehicle_number' => ['required', 'string', 'max:50'],
                'transport_charge_type' => ['required', Rule::in(['company_transport', 'transport_extra'])],
                'transport_freight' => ['required', 'numeric', 'min:0'],
                'transport_remark' => ['nullable', 'string', 'max:2000'],
            ],
            [
                'transport_charge_type.required' => 'Select Company Transport or Transport Charges Extra.',
            ],
        )->validate();

        return DB::transaction(function () use ($order, $actor, $resolved, $transportFreight, $transportRemark, $transportChargeType): array {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canBeSentForBilling()) {
                throw ValidationException::withMessages([
                    'status' => 'Only orders approved by Sales Manager can be sent for billing.',
                ]);
            }

            $locked->sendForBilling(
                userId: $actor->id,
                vehicleNumber: $resolved['vehicle_number'],
                transportFreight: $transportFreight,
                transportRemark: $transportRemark,
                vehicleId: $resolved['vehicle_id'],
                transportChargeType: $transportChargeType,
            );

            return ['order' => $locked->fresh()];
        });
    }

    /**
     * @return array{vehicle_id: ?int, vehicle_number: string}
     */
    private function resolveVehicle(?int $vehicleId, ?string $vehicleNumber): array
    {
        if ($vehicleId !== null && $vehicleId > 0) {
            $vehicle = Vehicle::query()->active()->find($vehicleId);

            if ($vehicle === null) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['Select a valid active vehicle.'],
                ]);
            }

            return [
                'vehicle_id' => $vehicle->id,
                'vehicle_number' => $vehicle->vehicle_number,
            ];
        }

        $normalized = Vehicle::normalizeVehicleNumber((string) $vehicleNumber);

        if (blank($normalized)) {
            throw ValidationException::withMessages([
                'vehicle_number' => ['Vehicle number is required.'],
            ]);
        }

        $existing = Vehicle::query()
            ->where('vehicle_number', $normalized)
            ->first();

        return [
            'vehicle_id' => $existing?->id,
            'vehicle_number' => $normalized,
        ];
    }
}
