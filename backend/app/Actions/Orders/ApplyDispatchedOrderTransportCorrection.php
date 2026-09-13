<?php

namespace App\Actions\Orders;

use App\Enums\TransportChargeType;
use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Inventory\OrderDispatchStockService;
use App\Services\Orders\OrderBillingTransportCalculator;
use App\Services\Orders\OrderLineCalculationService;
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
     * Apply a Director-approved one-time full bill correction on a dispatched order.
     * Status remains dispatched. Permission is consumed on save.
     * Does not create or resend a Tally Sales voucher.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @return array{order: Order, request: OrderEditPermissionRequest}
     */
    public function execute(
        Order $order,
        User $actor,
        int $vehicleId,
        string $transportChargeType,
        float $transportFreight,
        ?array $items = null,
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

        $itemsProvided = $items !== null;
        $normalizedItems = $this->normalizeIncomingItems($items);

        return DB::transaction(function () use (
            $order,
            $actor,
            $vehicle,
            $transportChargeType,
            $transportFreight,
            $itemsProvided,
            $normalizedItems,
        ): array {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['items.product:id,product_name,nos_per_case,dealer_price,gst_percentage,uom']);

            if ($locked->status !== Order::STATUS_DISPATCHED) {
                throw ValidationException::withMessages([
                    'status' => ['Only dispatched orders can receive this correction.'],
                ]);
            }

            /** @var OrderEditPermissionRequest|null $permission */
            $permission = OrderEditPermissionRequest::query()
                ->where('order_id', $locked->id)
                ->whereIn('status', OrderEditPermissionRequest::unlockedStatuses())
                ->lockForUpdate()
                ->first();

            if ($permission === null) {
                throw ValidationException::withMessages([
                    'status' => ['Director approval is required, and permission is valid for one save only.'],
                ]);
            }

            $oldValues = $this->snapshot($locked);
            $oldQtyByProduct = $this->quantitiesByProduct($locked);
            $replaceItems = false;

            if ($itemsProvided) {
                if ($normalizedItems === [] && $locked->items->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'items' => ['Add at least one product.'],
                    ]);
                }
                $replaceItems = $normalizedItems !== [];
            }

            if ($replaceItems) {
                $calculatedItems = $this->calculateItems($normalizedItems);
                OrderItem::withoutEvents(function () use ($locked, $calculatedItems): void {
                    $locked->items()->delete();
                    foreach ($calculatedItems as $item) {
                        $locked->items()->create($item);
                    }
                });
                $locked->unsetRelation('items');
                $locked->load(['items.product:id,product_name,nos_per_case,dealer_price,gst_percentage,uom']);
            }

            $billingTransport = OrderBillingTransportCalculator::calculateForOrder(
                $locked,
                $transportChargeType,
                $transportFreight,
            );

            $locked->update([
                'vehicle_id' => $vehicle->id,
                'vehicle_number' => $vehicle->vehicle_number,
                'subtotal' => $billingTransport['subtotal'],
                'discount_amount' => $billingTransport['discount_amount'],
                ...OrderBillingTransportCalculator::persistedAttributes($billingTransport),
            ]);

            $fresh = $locked->fresh(['items.product']) ?? $locked;

            if ($replaceItems) {
                app(OrderDispatchStockService::class)->adjustForCorrectedQuantities($fresh, $oldQtyByProduct, $actor);
            }

            app(DealerLedgerPostingService::class)->updateExistingSalesDebit($fresh);

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
                'order' => $fresh->fresh(['items.product']) ?? $fresh,
                'request' => $used,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     * @return list<array<string, mixed>>
     */
    private function normalizeIncomingItems(?array $items): array
    {
        if ($items === null) {
            return [];
        }

        return array_values(array_filter(
            $items,
            fn (mixed $row): bool => is_array($row) && (int) ($row['product_id'] ?? 0) > 0,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function calculateItems(array $items): array
    {
        $calculator = app(OrderLineCalculationService::class);
        $calculatedItems = [];

        foreach ($items as $index => $item) {
            $product = Product::query()
                ->whereKey($item['product_id'])
                ->where('status', true)
                ->first();

            if ($product === null) {
                throw ValidationException::withMessages([
                    "items.$index.product_id" => 'Selected product is not active.',
                ]);
            }

            try {
                $calculatedItems[] = $calculator->calculateForProduct(
                    product: $product,
                    caseQuantity: (int) ($item['case_quantity'] ?? 0),
                    ratePerNo: (float) ($item['rate_per_no'] ?? $item['rate'] ?? 0),
                    requestedDiscountPercentage: (float) ($item['discount_percentage'] ?? 0),
                    requestedGstPercentage: (float) ($item['gst_percentage'] ?? $product->gst_percentage ?? 0),
                    enforceDiscountRule: false,
                    rateType: (string) ($item['rate_type'] ?? OrderLineCalculationService::RATE_TYPE_PRICE_LIST),
                );
            } catch (ValidationException $exception) {
                $messages = $exception->errors();
                $firstKey = array_key_first($messages);
                $firstMessage = $messages[$firstKey][0] ?? 'Invalid row data.';

                throw ValidationException::withMessages([
                    "items.$index.".($firstKey ?? 'case_quantity') => $firstMessage,
                ]);
            }
        }

        return $calculatedItems;
    }

    /**
     * @return array<int, float>
     */
    private function quantitiesByProduct(Order $order): array
    {
        $qtyByProduct = [];

        foreach ($order->items as $item) {
            $productId = (int) $item->product_id;
            if ($productId < 1) {
                continue;
            }

            $qtyByProduct[$productId] = round(($qtyByProduct[$productId] ?? 0) + $item->quantityInNos(), 3);
        }

        return $qtyByProduct;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        $order->loadMissing(['items.product:id,product_name']);
        $chargeType = OrderBillingTransportCalculator::resolveChargeType($order)?->value
            ?? TransportChargeType::TransportExtra->value;
        $billing = OrderBillingTransportCalculator::calculateForOrder(
            $order,
            $chargeType,
            (float) ($order->transport_amount ?? 0),
            strict: false,
        );

        return [
            'items' => $order->items->map(function (OrderItem $item): string {
                $name = $item->product?->product_name ?: 'Product #'.$item->product_id;

                return sprintf(
                    '%s | Cases %s | Qty %s | Rate ₹%s | Disc %s%% | Line %s',
                    $name,
                    (string) ($item->case_quantity ?? 0),
                    (string) $item->quantityInNos(),
                    number_format((float) ($item->rate_per_no ?? $item->rate ?? 0), 2, '.', ''),
                    number_format((float) ($item->discount_percentage ?? 0), 2, '.', ''),
                    number_format((float) ($item->line_total ?? $item->final_amount ?? 0), 2, '.', ''),
                );
            })->implode("\n"),
            'vehicle_number' => $order->vehicle_number,
            'transport_charge_type' => $order->transport_charge_type,
            'transport_amount' => $order->transport_amount !== null
                ? round((float) $order->transport_amount, 2)
                : null,
            'subtotal' => round((float) $billing['subtotal'], 2),
            'discount_amount' => round((float) $billing['discount_amount'], 2),
            'taxable_amount' => round((float) $billing['taxable_amount_after_transport'], 2),
            'gst_amount' => round((float) $billing['gst_amount'], 2),
            'round_off' => round((float) $billing['round_off'], 2),
            'grand_total' => round((float) $order->grand_total, 2),
        ];
    }
}
