@php
    use App\Models\Order;

    /** @var Order $record */
    $money = static fn ($value): string => '₹'.number_format((float) $value, 2);

    $subtotal = (float) $record->subtotal;
    $discount = (float) $record->discount_amount;
    $taxable = $record->taxable_amount_after_transport !== null
        ? (float) $record->taxable_amount_after_transport
        : max(0, $subtotal - $discount);
    $cgst = round(((float) $record->gst_amount) / 2, 2);
    $sgst = round(((float) $record->gst_amount) / 2, 2);
    $grandTotal = (float) $record->grand_total;

    $rows = [
        ['label' => 'Subtotal', 'value' => $money($subtotal), 'emphasis' => false],
        ['label' => 'Discount', 'value' => $money($discount), 'emphasis' => false],
        ['label' => 'Taxable Value', 'value' => $money($taxable), 'emphasis' => false],
        ['label' => 'CGST', 'value' => $money($cgst), 'emphasis' => false],
        ['label' => 'SGST', 'value' => $money($sgst), 'emphasis' => false],
        ['label' => 'Grand Total', 'value' => $money($grandTotal), 'emphasis' => true],
    ];
@endphp

<div class="flex w-full justify-end">
    <dl class="m-0 w-full max-w-xs space-y-1.5 p-0">
        @foreach ($rows as $row)
            <div class="flex items-baseline justify-between gap-6 {{ $row['emphasis'] ? 'mt-1 border-t border-gray-200 pt-2 dark:border-gray-700' : '' }}">
                <dt class="{{ $row['emphasis'] ? 'text-sm font-semibold text-gray-950 dark:text-white' : 'text-sm text-gray-500 dark:text-gray-400' }}">
                    {{ $row['label'] }}
                </dt>
                <dd class="m-0 text-right tabular-nums {{ $row['emphasis'] ? 'text-base font-bold text-gray-950 dark:text-white' : 'text-sm text-gray-950 dark:text-gray-100' }}">
                    {{ $row['value'] }}
                </dd>
            </div>
        @endforeach
    </dl>
</div>
