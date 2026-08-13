@php
    use App\Models\Order;

    /** @var Order $record */
    $record->loadMissing('items.product:id,product_name,product_code');
    $money = static fn ($value): string => '₹'.number_format((float) $value, 2);
    $pct = static function ($value): string {
        $n = (float) $value;
        if ($n == (int) $n) {
            return ((int) $n).'%';
        }

        return rtrim(rtrim(number_format($n, 2), '0'), '.').'%';
    };
@endphp

<div style="overflow-x:auto;width:100%;border:1px solid #e2e8f0;border-radius:0.75rem;">
    <table style="width:100%;min-width:880px;border-collapse:collapse;font-size:0.8125rem;">
        <thead>
            <tr style="background:#f8fafc;">
                <th style="padding:0.65rem 0.75rem;text-align:left;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Product</th>
                <th style="padding:0.65rem 0.75rem;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Cases</th>
                <th style="padding:0.65rem 0.75rem;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Qty</th>
                <th style="padding:0.65rem 0.75rem;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Rate</th>
                <th style="padding:0.65rem 0.75rem;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Disc %</th>
                <th style="padding:0.65rem 0.75rem;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Taxable Amount</th>
                <th style="padding:0.65rem 0.75rem;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">CGST</th>
                <th style="padding:0.65rem 0.75rem;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">SGST</th>
                <th style="padding:0.65rem 0.75rem;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($record->items as $item)
                @php
                    $cases = (int) ($item->case_quantity ?? 0);
                    $qty = (int) ($item->total_quantity_nos ?? $item->quantity ?? 0);
                    $rate = $item->rate_per_no ?? $item->rate ?? 0;
                    $gstAmount = (float) ($item->gst_amount ?? 0);
                    $cgst = round($gstAmount / 2, 2);
                    $sgst = round($gstAmount / 2, 2);
                    $name = $item->product?->product_name ?: '-';
                    $code = trim((string) ($item->product?->product_code ?? ''));
                @endphp
                <tr>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;vertical-align:top;min-width:180px;">
                        <div style="font-weight:600;color:#0f172a;line-height:1.3;">{{ $name }}</div>
                        @if ($code !== '')
                            <div style="font-size:0.75rem;color:#64748b;margin-top:0.15rem;">{{ $code }}</div>
                        @endif
                    </td>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;">{{ $cases }}</td>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;">{{ $qty }}</td>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;">{{ $money($rate) }}</td>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;">{{ $pct($item->discount_percentage ?? 0) }}</td>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;">{{ $money($item->taxable_amount ?? 0) }}</td>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;">{{ $money($cgst) }}</td>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;">{{ $money($sgst) }}</td>
                    <td style="padding:0.7rem 0.75rem;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;font-weight:700;">{{ $money($item->final_amount ?? $item->line_total ?? 0) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="padding:1rem;text-align:center;color:#64748b;">No products</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@php
    $totalCases = (int) $record->items->sum(fn ($item) => (int) ($item->case_quantity ?? 0));
@endphp
@if ($record->items->isNotEmpty())
    <div style="margin-top:0.75rem;font-size:0.875rem;font-weight:700;color:#0f172a;">
        Total Cases: {{ $totalCases }}
    </div>
@endif
