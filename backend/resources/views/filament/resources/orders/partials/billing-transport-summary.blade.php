@php
    use App\Services\Orders\OrderBillingTransportCalculator;

    /** @var \App\Models\Order $record */

    $billing = OrderBillingTransportCalculator::present($record);
    $hasAdjustment = OrderBillingTransportCalculator::hasSavedAdjustment($record);
    $typeLabel = $billing['transport_charge_type_label'] ?? '—';
    $vehicle = $record->vehicle_number ?: '—';
    $charges = $billing['transport_charges'];
    $original = $billing['original_grand_total'];
    $adjustment = $billing['transport_adjustment'];
    $final = $billing['final_grand_total'];
@endphp

@if (filled($record->vehicle_number) || $charges !== null || $hasAdjustment)
    <div class="pg-billing-transport" style="margin:0 0 10px;">
        <div style="display:flex;justify-content:space-between;gap:16px;padding:4px 0;">
            <span style="font-size:13px;color:#64748B;font-weight:500;">Vehicle No</span>
            <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ $vehicle }}</span>
        </div>
        <div style="display:flex;justify-content:space-between;gap:16px;padding:4px 0;">
            <span style="font-size:13px;color:#64748B;font-weight:500;">Transport Charge Type</span>
            <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ $typeLabel }}</span>
        </div>
        <div style="display:flex;justify-content:space-between;gap:16px;padding:4px 0;">
            <span style="font-size:13px;color:#64748B;font-weight:500;">Transport Charges</span>
            <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ $charges === null ? '—' : ($hasAdjustment ? OrderBillingTransportCalculator::formatAdjustment((float) $adjustment) : OrderBillingTransportCalculator::formatMoney((float) $charges)) }}</span>
        </div>
        @if ($hasAdjustment)
            <div style="display:flex;justify-content:space-between;gap:16px;padding:4px 0;">
                <span style="font-size:13px;color:#64748B;font-weight:500;">Original Order Total</span>
                <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ OrderBillingTransportCalculator::formatMoney((float) $original) }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:16px;padding:10px 0 0;margin-top:6px;border-top:1px solid #E2E8F0;">
                <span style="font-size:14px;color:#0F172A;font-weight:700;">Final Bill Amount</span>
                <span style="font-size:18px;color:#0F766E;font-weight:800;">{{ OrderBillingTransportCalculator::formatMoney((float) $final) }}</span>
            </div>
        @endif
    </div>
@endif
