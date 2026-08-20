@php
    use App\Models\Order;

    /** @var Order $record */
    $record->loadMissing('items.product:id,product_name,product_code');
    $money = static fn ($value): string => '₹'.number_format((float) $value, 2, '.', ',');
    $pct = static function ($value): string {
        $n = (float) $value;
        if ($n == (int) $n) {
            return ((int) $n).'%';
        }

        return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.').'%';
    };
    $totalCases = (int) $record->items->sum(fn ($item) => (int) ($item->case_quantity ?? 0));
@endphp

<div class="pg-order-items">
    <style>
        .pg-order-items { width: 100%; }
        .pg-order-items__scroll {
            width: 100%;
            overflow-x: auto;
            border: 1px solid #E2E8F0;
            border-radius: 0.75rem;
            background: #fff;
        }
        .pg-order-items__table {
            width: 100%;
            min-width: 980px;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13px;
        }
        .pg-order-items__table th {
            padding: 12px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748B;
            background: #F8FAFC;
            border-bottom: 1px solid #E2E8F0;
            white-space: nowrap;
        }
        .pg-order-items__table th.num,
        .pg-order-items__table td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .pg-order-items__table td {
            padding: 12px 14px;
            vertical-align: top;
            border-bottom: 1px solid #F1F5F9;
            color: #0F172A;
        }
        .pg-order-items__table tr:last-child td { border-bottom: 0; }
        .pg-order-items__name {
            margin: 0;
            font-weight: 700;
            color: #0F172A;
            line-height: 1.35;
            max-width: 280px;
            word-break: break-word;
        }
        .pg-order-items__code {
            margin: 3px 0 0;
            font-size: 12px;
            color: #94A3B8;
            line-height: 1.3;
        }
        .pg-order-items__total {
            margin: 12px 0 0;
            font-size: 13px;
            font-weight: 700;
            color: #0F172A;
        }
        .pg-order-items__empty {
            padding: 20px;
            text-align: center;
            color: #94A3B8;
        }
    </style>

    <div class="pg-order-items__scroll">
        <table class="pg-order-items__table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="num">Cases</th>
                    <th class="num">Qty</th>
                    <th class="num">Rate</th>
                    <th class="num">Disc %</th>
                    <th class="num">Taxable Amount</th>
                    <th class="num">CGST</th>
                    <th class="num">SGST</th>
                    <th class="num">Total</th>
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
                        $amountWithoutGst = $item->taxable_amount ?? $item->base_amount ?? 0;
                        $name = $item->product?->product_name ?: '-';
                        $code = trim((string) ($item->product?->product_code ?? ''));
                    @endphp
                    <tr>
                        <td>
                            <p class="pg-order-items__name">{{ $name }}</p>
                            @if ($code !== '')
                                <p class="pg-order-items__code">{{ $code }}</p>
                            @endif
                        </td>
                        <td class="num">{{ $cases }}</td>
                        <td class="num">{{ $qty }}</td>
                        <td class="num">{{ $money($rate) }}</td>
                        <td class="num">{{ $pct($item->discount_percentage ?? 0) }}</td>
                        <td class="num">{{ $money($amountWithoutGst) }}</td>
                        <td class="num">{{ $money($cgst) }}</td>
                        <td class="num">{{ $money($sgst) }}</td>
                        <td class="num" style="font-weight:700;">{{ $money($item->final_amount ?? $item->line_total ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="pg-order-items__empty">No products</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($record->items->isNotEmpty())
        <div class="pg-order-items__total">Total Cases: {{ $totalCases }}</div>
    @endif
</div>
