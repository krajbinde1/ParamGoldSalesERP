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

<div class="w-full overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
    <table class="w-full min-w-[960px] border-collapse text-sm">
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800/60">
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-left font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">Product</th>
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-right font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">Cases</th>
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-right font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">Qty</th>
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-right font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">Rate</th>
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-right font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">Disc %</th>
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-right font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">Amount Without GST</th>
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-right font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">CGST</th>
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-right font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">SGST</th>
                <th class="whitespace-nowrap border-b border-gray-200 px-3 py-2.5 text-right font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">Total</th>
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
                <tr class="align-top">
                    <td class="min-w-[200px] border-b border-gray-100 px-3 py-2.5 dark:border-gray-800">
                        <div class="font-semibold leading-5 text-gray-950 dark:text-white">{{ $name }}</div>
                        @if ($code !== '')
                            <div class="mt-0.5 text-xs leading-4 text-gray-500 dark:text-gray-400">{{ $code }}</div>
                        @endif
                    </td>
                    <td class="whitespace-nowrap border-b border-gray-100 px-3 py-2.5 text-right tabular-nums text-gray-950 dark:border-gray-800 dark:text-gray-100">{{ $cases }}</td>
                    <td class="whitespace-nowrap border-b border-gray-100 px-3 py-2.5 text-right tabular-nums text-gray-950 dark:border-gray-800 dark:text-gray-100">{{ $qty }}</td>
                    <td class="whitespace-nowrap border-b border-gray-100 px-3 py-2.5 text-right tabular-nums text-gray-950 dark:border-gray-800 dark:text-gray-100">{{ $money($rate) }}</td>
                    <td class="whitespace-nowrap border-b border-gray-100 px-3 py-2.5 text-right tabular-nums text-gray-950 dark:border-gray-800 dark:text-gray-100">{{ $pct($item->discount_percentage ?? 0) }}</td>
                    <td class="whitespace-nowrap border-b border-gray-100 px-3 py-2.5 text-right tabular-nums text-gray-950 dark:border-gray-800 dark:text-gray-100">{{ $money($amountWithoutGst) }}</td>
                    <td class="whitespace-nowrap border-b border-gray-100 px-3 py-2.5 text-right tabular-nums text-gray-950 dark:border-gray-800 dark:text-gray-100">{{ $money($cgst) }}</td>
                    <td class="whitespace-nowrap border-b border-gray-100 px-3 py-2.5 text-right tabular-nums text-gray-950 dark:border-gray-800 dark:text-gray-100">{{ $money($sgst) }}</td>
                    <td class="whitespace-nowrap border-b border-gray-100 px-3 py-2.5 text-right font-semibold tabular-nums text-gray-950 dark:border-gray-800 dark:text-white">{{ $money($item->final_amount ?? $item->line_total ?? 0) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">No products</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@php
    $totalCases = (int) $record->items->sum(fn ($item) => (int) ($item->case_quantity ?? 0));
@endphp
@if ($record->items->isNotEmpty())
    <div class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">
        Total Cases: {{ $totalCases }}
    </div>
@endif
