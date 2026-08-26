@php
    use App\Services\Orders\OrderBillingTransportCalculator;

    /** @var \App\Models\Order $record */

    $billing = OrderBillingTransportCalculator::present($record);
    $hasAdjustment = OrderBillingTransportCalculator::hasSavedAdjustment($record);
    $typeLabel = $billing['transport_charge_type_label'] ?? '—';
    $vehicle = $record->vehicle_number ?: '—';
    $charges = $billing['transport_charges'];
    $adjustment = $billing['transport_adjustment'];
    $subtotal = $billing['subtotal'];
    $discount = $billing['discount_amount'];
    $taxable = $billing['taxable_amount_after_transport'];
    $gst = $billing['gst_amount'];
    $final = $billing['final_grand_total'];
    $roundOff = $billing['round_off'];
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
                <span style="font-size:13px;color:#64748B;font-weight:500;">Subtotal</span>
                <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ OrderBillingTransportCalculator::formatMoney((float) $subtotal) }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:16px;padding:4px 0;">
                <span style="font-size:13px;color:#64748B;font-weight:500;">Discount</span>
                <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ OrderBillingTransportCalculator::formatMoney((float) $discount) }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:16px;padding:4px 0;">
                <span style="font-size:13px;color:#64748B;font-weight:500;">Taxable Value</span>
                <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ OrderBillingTransportCalculator::formatMoney((float) $taxable) }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:16px;padding:4px 0;">
                <span style="font-size:13px;color:#64748B;font-weight:500;">GST</span>
                <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ OrderBillingTransportCalculator::formatMoney((float) $gst) }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:16px;padding:4px 0;">
                <span style="font-size:13px;color:#64748B;font-weight:500;">Round Off</span>
                <span style="font-size:13px;color:#0F172A;font-weight:600;">{{ OrderBillingTransportCalculator::formatRoundOff((float) $roundOff) }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:16px;padding:10px 0 0;margin-top:6px;border-top:1px solid #E2E8F0;">
                <span style="font-size:14px;color:#0F172A;font-weight:700;">Grand Total</span>
                <span style="font-size:18px;color:#0F766E;font-weight:800;">{{ OrderBillingTransportCalculator::formatMoney((float) $final) }}</span>
            </div>
        @endif
    </div>
@endif
