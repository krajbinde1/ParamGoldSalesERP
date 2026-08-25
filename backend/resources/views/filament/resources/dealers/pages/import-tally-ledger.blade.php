@php
    use App\Support\IndianCurrency;
    $preview = $preview ?? null;
    $result = $result ?? null;
    $dealer = $this->selectedDealerDetails();
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-5 py-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Selected ERP Dealer</div>
            <div class="mt-1 text-lg font-semibold text-slate-950">{{ $dealer['label'] }}</div>
            <div class="mt-3 grid gap-3 sm:grid-cols-4">
                <div>
                    <div class="text-xs font-semibold uppercase text-slate-500">Dealer Code</div>
                    <div class="mt-1 font-medium text-slate-900">{{ $dealer['dealer_code'] ?: '—' }}</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase text-slate-500">Firm Name</div>
                    <div class="mt-1 font-medium text-slate-900">{{ $dealer['firm_name'] }}</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase text-slate-500">Village</div>
                    <div class="mt-1 font-medium text-slate-900">{{ $dealer['village'] ?: '—' }}</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase text-slate-500">District</div>
                    <div class="mt-1 font-medium text-slate-900">{{ $dealer['district'] ?: '—' }}</div>
                </div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([1 => 'Upload Tally Excel', 2 => 'Preview Ledger', 3 => 'Import Summary'] as $n => $label)
                <div @class([
                    'rounded-xl border px-4 py-3',
                    'border-primary-500 bg-primary-50 text-primary-700' => $step === $n,
                    'border-gray-200 bg-white' => $step !== $n,
                ])>
                    <div class="text-xs font-semibold uppercase tracking-wide">Step {{ $n }}</div>
                    <div class="mt-1 text-sm font-medium">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        @if ($step === 1)
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <h2 class="text-lg font-semibold text-gray-950">Upload Tally dealer ledger</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Financial start date is <strong>01 Apr 2026</strong>. Only genuine transactions on or after that date are imported into
                    <strong>{{ $dealer['firm_name'] }}</strong>.
                    Opening Balance is taken from Tally when shown; otherwise it is ₹0.00.
                    Existing ERP opening balances are ignored.
                </p>
                <form wire:submit="previewUpload" class="mt-4 max-w-2xl space-y-4">
                    {{ $this->form }}
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="previewUpload,file">
                        <span wire:loading.remove wire:target="previewUpload,file">Preview Ledger</span>
                        <span wire:loading wire:target="previewUpload,file">Reading Excel…</span>
                    </x-filament::button>
                </form>
            </div>
        @endif

        @if ($step === 2 && is_array($preview))
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950">Import preview</h2>
                        <p class="mt-1 text-sm text-gray-600">Review the Tally ledger, then import into the selected ERP dealer.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button color="gray" wire:click="resetUpload">Upload Another File</x-filament::button>
                        @if (! empty($preview['can_import']))
                            <x-filament::button wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport">
                                <span wire:loading.remove wire:target="runImport">Confirm &amp; Import</span>
                                <span wire:loading wire:target="runImport">Importing…</span>
                            </x-filament::button>
                        @endif
                    </div>
                </div>

                @if (($preview['parse_errors'] ?? []) !== [])
                    <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                        <div class="font-semibold">Tally ledger could not be imported</div>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($preview['parse_errors'] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold uppercase text-slate-500">ERP Dealer</div>
                        <div class="mt-1 font-semibold text-slate-900">{{ $dealer['firm_name'] }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold uppercase text-slate-500">Tally Ledger</div>
                        <div class="mt-1 font-semibold text-slate-900">{{ $preview['tally_ledger_name'] }}</div>
                    </div>
                </div>

                @if (! empty($preview['names_differ']))
                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                        ⚠ Tally ledger name is different from the selected ERP dealer. Please verify before importing.
                    </p>
                @endif

                <div>
                    <div class="mb-2 text-xs font-semibold uppercase text-slate-500">Ledger Summary</div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase text-slate-500">Opening Balance</div>
                            <div class="mt-1 font-semibold text-slate-900">
                                {{ IndianCurrency::formatDrCr(($preview['opening_balance_type'] ?? 'debit') === 'credit' ? -1 * (float) $preview['opening_balance'] : (float) $preview['opening_balance']) }}
                                @if (empty($preview['opening_balance_explicit']))
                                    <span class="block text-xs font-medium text-slate-500">Not shown in Tally — set to ₹0.00</span>
                                @endif
                            </div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase text-slate-500">Transaction Count</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ (int) $preview['transaction_count'] }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase text-slate-500">Total Debit including opening</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ IndianCurrency::formatExact($preview['total_debit'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase text-slate-500">Total Credit</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ IndianCurrency::formatExact($preview['total_credit'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase text-slate-500">Current Outstanding</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ IndianCurrency::formatDrCr($preview['erp_closing_signed'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase text-slate-500">Tally Closing Balance</div>
                            <div class="mt-1 font-semibold text-slate-900">
                                {{ $preview['tally_closing_balance'] !== null ? IndianCurrency::formatDrCr(($preview['tally_closing_balance_type'] ?? 'debit') === 'credit' ? -1 * (float) $preview['tally_closing_balance'] : (float) $preview['tally_closing_balance']) : '—' }}
                            </div>
                        </div>
                        <div class="rounded-lg border px-4 py-3 {{ ($preview['balance_matched'] ?? null) === true ? 'border-emerald-200 bg-emerald-50' : (($preview['balance_matched'] ?? null) === false ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50') }}">
                            <div class="text-xs font-semibold uppercase text-slate-500">ERP Calculated Closing Balance</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ IndianCurrency::formatDrCr($preview['erp_closing_signed'] ?? 0) }}</div>
                            <div class="mt-1 text-xs text-slate-600">
                                @if (($preview['balance_matched'] ?? null) === true)
                                    ✓ Tally Balance Matched
                                @elseif (($preview['balance_matched'] ?? null) === false)
                                    ⚠ Tally Balance Mismatch — Confirm &amp; Import is blocked
                                @else
                                    Tally closing not provided
                                @endif
                                @if ($preview['difference'] !== null)
                                    <br>Difference: {{ IndianCurrency::formatExact(abs((float) $preview['difference'])) }}
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                @if (($preview['sample_transactions'] ?? []) !== [])
                    <div class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Date</th>
                                    <th class="px-3 py-2 text-left">Particulars</th>
                                    <th class="px-3 py-2 text-left">Vch Type</th>
                                    <th class="px-3 py-2 text-left">Vch No.</th>
                                    <th class="px-3 py-2 text-right">Debit</th>
                                    <th class="px-3 py-2 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['sample_transactions'] as $row)
                                    <tr class="border-t border-slate-100">
                                        <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                                        <td class="px-3 py-2">{{ $row['particulars'] }}</td>
                                        <td class="px-3 py-2">{{ $row['voucher_type'] ?: '—' }}</td>
                                        <td class="px-3 py-2">{{ $row['voucher_no'] ?: '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ (float) $row['debit'] > 0 ? IndianCurrency::formatExact($row['debit']) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ (float) $row['credit'] > 0 ? IndianCurrency::formatExact($row['credit']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        @if ($step === 3 && is_array($result))
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold text-gray-950">Import completed</h2>
                <p class="text-sm text-gray-600">Dealer: <strong>{{ $result['dealer_name'] }}</strong></p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <div class="text-xs font-semibold uppercase text-emerald-700">Imported Transactions</div>
                        <div class="mt-1 text-xl font-bold text-emerald-900">{{ $result['imported_count'] }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold uppercase text-slate-500">Duplicate / Skipped</div>
                        <div class="mt-1 text-xl font-bold text-slate-900">{{ $result['duplicate_count'] }}</div>
                    </div>
                    <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3">
                        <div class="text-xs font-semibold uppercase text-rose-700">Failed Transactions</div>
                        <div class="mt-1 text-xl font-bold text-rose-900">{{ $result['failed_count'] }}</div>
                    </div>
                </div>
                <p class="text-sm text-slate-700">
                    Opening: <strong>{{ $result['opening_label'] }}</strong>
                    · Current Outstanding: <strong>{{ $result['outstanding_label'] }}</strong>
                </p>
                @if (($result['balance_matched'] ?? null) === true)
                    <p class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">✓ Tally Balance Matched</p>
                @elseif (($result['balance_matched'] ?? null) === false)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <strong>⚠ Tally Balance Mismatch</strong>
                        <div class="mt-1">
                            Tally Closing: {{ $result['tally_closing'] !== null ? IndianCurrency::formatDrCr(($result['tally_closing_type'] ?? 'debit') === 'credit' ? -1 * (float) $result['tally_closing'] : (float) $result['tally_closing']) : '—' }}
                            · ERP Calculated: {{ IndianCurrency::formatDrCr(($result['erp_closing_type'] ?? 'debit') === 'credit' ? -1 * (float) $result['erp_closing'] : (float) $result['erp_closing']) }}
                            · Difference: {{ $result['difference'] !== null ? IndianCurrency::formatDrCr($result['difference']) : '—' }}
                        </div>
                    </div>
                @endif
                <div class="flex flex-wrap gap-3">
                    <x-filament::button :href="\App\Filament\Resources\Dealers\DealerResource::getUrl('ledger', ['record' => $result['dealer_id']])" tag="a">
                        Open Dealer Ledger
                    </x-filament::button>
                    <x-filament::button color="gray" wire:click="resetUpload">Import Another File</x-filament::button>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
