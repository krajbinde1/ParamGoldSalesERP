@php
    use App\Models\Order;
    use App\Services\Orders\OrderBillingTransportCalculator;

    /** @var Order $record */
    $money = static fn ($value): string => OrderBillingTransportCalculator::formatMoney((float) $value);

    $subtotal = (float) $record->subtotal;
    $discount = (float) $record->discount_amount;
    $taxable = $record->taxable_amount_after_transport !== null
        ? (float) $record->taxable_amount_after_transport
        : max(0, $subtotal - $discount);
    $cgst = round(((float) $record->gst_amount) / 2, 2);
    $sgst = round(((float) $record->gst_amount) / 2, 2);
    $hasTransportAdjustment = OrderBillingTransportCalculator::hasSavedAdjustment($record);
    $billing = OrderBillingTransportCalculator::present($record);
    $grandTotal = (float) $billing['final_grand_total'];

    $rows = [
        ['label' => 'Subtotal', 'value' => $money($subtotal), 'emphasis' => false],
        ['label' => 'Discount', 'value' => $money($discount), 'emphasis' => false],
        ['label' => 'Taxable Value', 'value' => $money($taxable), 'emphasis' => false],
        ['label' => 'CGST', 'value' => $money($cgst), 'emphasis' => false],
        ['label' => 'SGST', 'value' => $money($sgst), 'emphasis' => false],
    ];

    if ($hasTransportAdjustment) {
        $rows[] = ['label' => 'Original Grand Total', 'value' => $money((float) $billing['original_grand_total']), 'emphasis' => false];
        $rows[] = ['label' => 'Vehicle No', 'value' => $record->vehicle_number ?: '—', 'emphasis' => false];
        $rows[] = ['label' => 'Transport Type', 'value' => $billing['transport_charge_type_label'] ?: '—', 'emphasis' => false];
        $rows[] = ['label' => 'Transport Charges', 'value' => OrderBillingTransportCalculator::formatAdjustment((float) $billing['transport_adjustment']), 'emphasis' => false];
        $rows[] = ['label' => 'Final Grand Total', 'value' => $money($grandTotal), 'emphasis' => true];
    } else {
        if (filled($record->vehicle_number) || $record->transport_amount !== null) {
            $rows[] = ['label' => 'Vehicle No', 'value' => $record->vehicle_number ?: '—', 'emphasis' => false];
            $rows[] = ['label' => 'Transport Type', 'value' => $billing['transport_charge_type_label'] ?: '—', 'emphasis' => false];
            $rows[] = ['label' => 'Transport Charges', 'value' => $record->transport_amount === null ? '—' : $money((float) $record->transport_amount), 'emphasis' => false];
        }
        $rows[] = ['label' => 'Grand Total', 'value' => $money($grandTotal), 'emphasis' => true];
    }
@endphp

<div class="pg-order-summary">
    <style>
        .pg-order-summary { width: 100%; }
        .pg-order-summary__list {
            margin: 0;
            padding: 4px 0 0;
            list-style: none;
        }
        .pg-order-summary__row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 16px;
            padding: 7px 0;
        }
        .pg-order-summary__label {
            margin: 0;
            font-size: 13px;
            color: #64748B;
            font-weight: 500;
        }
        .pg-order-summary__value {
            margin: 0;
            font-size: 13px;
            color: #0F172A;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .pg-order-summary__row--grand {
            margin-top: 6px;
            padding-top: 12px;
            border-top: 1px solid #E2E8F0;
        }
        .pg-order-summary__row--grand .pg-order-summary__label {
            font-size: 14px;
            font-weight: 700;
            color: #0F172A;
        }
        .pg-order-summary__row--grand .pg-order-summary__value {
            font-size: 18px;
            font-weight: 800;
            color: #0F766E;
            letter-spacing: -0.02em;
        }
    </style>

    <dl class="pg-order-summary__list">
        @foreach ($rows as $row)
            <div class="pg-order-summary__row {{ $row['emphasis'] ? 'pg-order-summary__row--grand' : '' }}">
                <dt class="pg-order-summary__label">{{ $row['label'] }}</dt>
                <dd class="pg-order-summary__value">{{ $row['value'] }}</dd>
            </div>
        @endforeach
    </dl>
</div>
