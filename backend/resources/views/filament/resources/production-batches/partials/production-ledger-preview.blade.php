@php
    $money = static fn (mixed $v, int $d = 2): string => '₹'.number_format((float) $v, $d);
@endphp

<div class="space-y-6">
    <x-filament::section icon="heroicon-o-arrow-trending-down" icon-color="danger">
        <x-slot name="heading">
            Material Issues (Quantity Out)
        </x-slot>

        <div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-white/5 dark:ring-white/10">
            <div class="fi-ta-content relative overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="fi-ta-header-cell px-4 py-3 text-start text-sm font-semibold">Material</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-start text-sm font-semibold">Transaction</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-end text-sm font-semibold">Quantity Out</th>
                            @if ($showCosts)
                                <th class="fi-ta-header-cell px-4 py-3 text-end text-sm font-semibold">Value</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @forelse ($outLines as $i => $line)
                            <tr @class(['fi-ta-row hover:bg-gray-50 dark:hover:bg-white/5', 'bg-gray-50/80 dark:bg-white/[0.03]' => $i % 2 === 1])>
                                <td class="fi-ta-cell px-4 py-3 text-sm font-medium">{{ $line['material_name'] }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-sm">{{ $line['transaction'] }}</td>
                                <td class="fi-ta-cell px-4 py-3 text-end text-sm tabular-nums">{{ $line['quantity_out'] }}</td>
                                @if ($showCosts)
                                    <td class="fi-ta-cell px-4 py-3 text-end text-sm tabular-nums">{{ $money($line['value']) }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $showCosts ? 4 : 3 }}" class="fi-ta-cell px-4 py-6 text-center text-sm text-gray-500">No consumption lines.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-arrow-trending-up" icon-color="success">
        <x-slot name="heading">
            Finished Goods Inward (Quantity In)
        </x-slot>

        <div class="fi-ta-ctn overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="fi-ta-content overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="fi-ta-header-cell px-4 py-3 text-start text-sm font-semibold">Product</th>
                            <th class="fi-ta-header-cell px-4 py-3 text-end text-sm font-semibold">Quantity In</th>
                            @if ($showCosts)
                                <th class="fi-ta-header-cell px-4 py-3 text-end text-sm font-semibold">Batch Value</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="fi-ta-row">
                            <td class="fi-ta-cell px-4 py-3 text-sm font-medium">{{ $finishedProduct ?: '—' }}</td>
                            <td class="fi-ta-cell px-4 py-3 text-end text-sm tabular-nums">{{ $finishedQty }}</td>
                            @if ($showCosts)
                                <td class="fi-ta-cell px-4 py-3 text-end text-sm font-semibold tabular-nums">{{ $money($finishedValue) }}</td>
                            @endif
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>
</div>
