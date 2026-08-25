<?php

namespace App\Actions\Orders;

use App\Enums\TransportChargeType;
use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ApplyDispatchedOrderTransportCorrection
{
    /**
     * Apply a one-time transport correction on a dispatched order.
     * Status remains dispatched. Permission is consumed on save.
     *
     * @return array{order: Order, request: OrderEditPermissionRequest}
     */
    public function execute(
        Order $order,
        User $actor,
        int $vehicleId,
        string $transportChargeType,
        float $transportFreight,
    ): array {
        if (! Gate::forUser($actor)->allows('correctDispatchedTransport', $order)) {
            throw new AuthorizationException('Director approval is required before this dispatched order can be corrected.');
        }

        Validator::make(
            [
                'vehicle_id' => $vehicleId,
                'transport_charge_type' => $transportChargeType,
                'transport_freight' => $transportFreight,
            ],
            [
                'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'transport_charge_type' => ['required', Rule::in(array_column(TransportChargeType::cases(), 'value'))],
                'transport_freight' => ['required', 'numeric', 'min:0'],
            ],
            [
                'transport_charge_type.required' => 'Select Company Transport or Transport Charges Extra.',
            ],
        )->validate();

        $vehicle = Vehicle::query()->find($vehicleId);
        if ($vehicle === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['Select a valid vehicle.'],
            ]);
        }

        if (! $vehicle->is_active && (int) $order->vehicle_id !== (int) $vehicle->id) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['Select a valid active vehicle.'],
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $vehicle, $transportChargeType, $transportFreight): array {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Order::STATUS_DISPATCHED) {
                throw ValidationException::withMessages([
                    'status' => ['Only dispatched orders can receive this correction.'],
                ]);
            }

            /** @var OrderEditPermissionRequest|null $permission */
            $permission = OrderEditPermissionRequest::query()
                ->where('order_id', $locked->id)
                ->where('status', OrderEditPermissionRequest::STATUS_APPROVED)
                ->lockForUpdate()
                ->first();

            if ($permission === null) {
                throw ValidationException::withMessages([
                    'status' => ['Director approval is required, and permission is valid for one save only.'],
                ]);
            }

            $oldValues = $this->snapshot($locked);

            $locked->loadMissing('items');

            $billingTransport = OrderBillingTransportCalculator::calculateForOrder(
                $locked,
                $transportChargeType,
                $transportFreight,
            );

            $locked->update([
                'vehicle_id' => $vehicle->id,
                'vehicle_number' => $vehicle->vehicle_number,
                ...OrderBillingTransportCalculator::persistedAttributes($billingTransport),
            ]);

            $fresh = $locked->fresh() ?? $locked;

            $permission->update([
                'status' => OrderEditPermissionRequest::STATUS_USED,
                'edited_by' => $actor->id,
                'edited_at' => Carbon::now('Asia/Kolkata'),
                'old_values' => $oldValues,
                'new_values' => $this->snapshot($fresh),
            ]);

            $used = $permission->fresh() ?? $permission;
            $used->loadMissing(['requestedByUser:id,name', 'reviewedByUser:id,name', 'editedByUser:id,name']);

            $fresh->recordDetailsCorrected($actor, $used);

            return [
                'order' => $fresh->fresh() ?? $fresh,
                'request' => $used,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        return [
            'vehicle_number' => $order->vehicle_number,
            'transport_charge_type' => $order->transport_charge_type,
            'transport_amount' => $order->transport_amount !== null
                ? round((float) $order->transport_amount, 2)
                : null,
            'gst_amount' => round((float) $order->gst_amount, 2),
            'grand_total' => round((float) $order->grand_total, 2),
        ];
    }
}
