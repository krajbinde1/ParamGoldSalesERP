<?php

namespace App\Actions\Orders;

use App\Enums\TransportType;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderDispatchCalculationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
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
        ?string $dispatchDate = null,
        ?string $transporterName = null,
        ?string $vehicleNumber = null,
        ?string $lrNumber = null,
        ?UploadedFile $lrDocument = null,
    ): array {
        if (! Gate::forUser($actor)->allows('dispatch', $order)) {
            throw new AuthorizationException('You are not allowed to dispatch this order.');
        }

        if (! $order->canBeDispatched()) {
            throw ValidationException::withMessages([
                'status' => 'Only billed orders can be dispatched.',
            ]);
        }

        Validator::make(
            [
                'transport_type' => $transportType,
                'transport_amount' => $transportAmount,
                'dispatch_date' => $dispatchDate,
                'transporter_name' => $transporterName,
                'vehicle_number' => $vehicleNumber,
                'lr_number' => $lrNumber,
                'lr_document' => $lrDocument,
            ],
            [
                'transport_type' => ['required', 'in:'.implode(',', array_column(TransportType::cases(), 'value'))],
                'transport_amount' => ['required', 'numeric', 'min:0'],
                'dispatch_date' => ['nullable', 'date'],
                'transporter_name' => ['nullable', 'string', 'max:255'],
                'vehicle_number' => ['nullable', 'string', 'max:50'],
                'lr_number' => ['nullable', 'string', 'max:100'],
                'lr_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:10240'],
            ],
        )->validate();

        $calculation = $this->calculator->calculate($order, $transportType, $transportAmount);
        $lrPath = $lrDocument
            ? str_replace('\\', '/', $lrDocument->store('order-dispatch-docs', 'public'))
            : null;

        return DB::transaction(function () use (
            $order,
            $actor,
            $remark,
            $calculation,
            $dispatchDate,
            $transporterName,
            $vehicleNumber,
            $lrNumber,
            $lrPath,
        ): array {
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
                'dispatch_date' => $dispatchDate ?: now('Asia/Kolkata')->toDateString(),
                'dispatch_remark' => filled($remark) ? trim($remark) : null,
                'transporter_name' => filled($transporterName) ? trim($transporterName) : null,
                'vehicle_number' => filled($vehicleNumber) ? trim($vehicleNumber) : null,
                'lr_number' => filled($lrNumber) ? trim($lrNumber) : null,
                'lr_document_path' => $lrPath,
            ]);

            return [
                'order' => $order->fresh(),
                'calculation' => $calculation,
            ];
        });
    }
}
