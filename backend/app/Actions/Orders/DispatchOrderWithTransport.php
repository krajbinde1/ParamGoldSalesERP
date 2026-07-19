<?php

namespace App\Actions\Orders;

use App\Enums\TransportType;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderDispatchCalculationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DispatchOrderWithTransport
{
    public function __construct(
        private readonly OrderDispatchCalculationService $calculator,
    ) {}

    /**
     * @return array{order: Order, calculation: array<string, mixed>}
     */
    public function execute(
        Order $order,
        User $actor,
        string $transportType,
        float $transportAmount,
        ?string $remark = null,
    ): array {
        if (! Gate::forUser($actor)->allows('dispatch', $order)) {
            throw new AuthorizationException('You are not allowed to dispatch this order.');
        }

        if (! $order->canBeDispatched()) {
            throw ValidationException::withMessages([
                'status' => 'Only approved orders can be dispatched.',
            ]);
        }

        Validator::make(
            [
                'transport_type' => $transportType,
                'transport_amount' => $transportAmount,
            ],
            [
                'transport_type' => ['required', 'in:'.implode(',', array_column(TransportType::cases(), 'value'))],
                'transport_amount' => ['required', 'numeric', 'min:0'],
            ],
        )->validate();

        $calculation = $this->calculator->calculate($order, $transportType, $transportAmount);

        return DB::transaction(function () use ($order, $actor, $remark, $calculation): array {
            $order->update([
                'status' => Order::STATUS_DISPATCHED,
                'transport_type' => $calculation['transport_type'],
                'transport_amount' => $calculation['transport_amount'],
                'subtotal_before_transport' => $calculation['subtotal_before_transport'],
                'taxable_amount_after_transport' => $calculation['taxable_amount_after_transport'],
                'gst_amount' => $calculation['total_gst'],
                'grand_total' => $calculation['grand_total'],
                'dispatched_by' => $actor->id,
                'dispatched_at' => now('Asia/Kolkata'),
                'dispatch_remark' => filled($remark) ? trim($remark) : null,
            ]);

            return [
                'order' => $order->fresh(),
                'calculation' => $calculation,
            ];
        });
    }
}
