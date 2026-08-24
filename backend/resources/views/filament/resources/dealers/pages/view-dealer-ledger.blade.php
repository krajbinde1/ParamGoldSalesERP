@php
    use App\Support\IndianCurrency;

    $payload = $this->ledgerPayload();
    $summary = $payload['summary'];
    $ledger = $payload['ledger'];
    $verification = $payload['verification'];
    $start = \Illuminate\Support\Carbon::parse($summary['financial_start_date'])->format('d M Y');
@endphp

<x-filament-panels::page>
    <style>
        .pg-dealer-ledger-summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        @media (min-width: 768px) {
            .pg-dealer-ledger-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        .pg-dealer-ledger-card {
            border: 1px solid #E2E8F0;
            border-radius: 0.85rem;
            background: #FFFFFF;
            padding: 0.9rem 1rem;
        }
        .pg-dealer-ledger-card.is-outstanding {
            background: #FEF2F2;
            border-color: #FECACA;
        }
        .pg-dealer-ledger-card span {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #64748B;
        }
        .pg-dealer-ledger-card strong {
            display: block;
            margin-top: 0.3rem;
            font-size: 1.1rem;
            color: #0F172A;
        }
        .pg-dealer-ledger-card.is-outstanding span,
        .pg-dealer-ledger-card.is-outstanding strong { color: #991B1B; }
        .pg-dealer-ledger-note {
            margin: 0 0 1rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            line-height: 1.45;
        }
        .pg-dealer-ledger-note.is-info { background: #F8FAFC; border: 1px solid #E2E8F0; color: #475569; }
        .pg-dealer-ledger-note.is-ok { background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; font-weight: 650; }
        .pg-dealer-ledger-note.is-warn { background: #FFFBEB; border: 1px solid #FDE68A; color: #92400E; }
        .pg-dealer-ledger-table-wrap {
            overflow-x: auto;
            border: 1px solid #E2E8F0;
            border-radius: 0.85rem;
            background: #FFFFFF;
        }
        .pg-dealer-ledger-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .pg-dealer-ledger-table th,
        .pg-dealer-ledger-table td {
            padding: 0.7rem 0.85rem;
            border-bottom: 1px solid #F1F5F9;
            text-align: left;
            vertical-align: top;
        }
        .pg-dealer-ledger-table th {
            background: #F8FAFC;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #64748B;
        }
        .pg-dealer-ledger-table .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .pg-dealer-ledger-table tr:last-child td { border-bottom: 0; }
        .pg-dealer-ledger-empty {
            padding: 1.5rem;
            text-align: center;
            color: #64748B;
        }
    </style>

    @if (! $summary['has_tally_ledger'])
        <p class="pg-dealer-ledger-note is-info">
            No Tally ledger has been imported for this dealer yet. Opening Balance and Current Outstanding are
            <strong>{{ IndianCurrency::formatExact(0) }}</strong> until a Tally Excel is imported.
            The previous ERP opening balance is not used.
        </p>
    @elseif ($verification['balance_matched'] === true)
        <p class="pg-dealer-ledger-note is-ok">✓ Tally Balance Matched</p>
    @elseif ($verification['balance_matched'] === false)
        <div class="pg-dealer-ledger-note is-warn">
            <strong>⚠ Tally Balance Mismatch</strong>
            <div style="margin-top:6px;">
                Tally Closing Balance: {{ $verification['tally_closing_label'] ?: '—' }}
                · ERP Calculated Balance: {{ $verification['erp_closing_label'] }}
                · Difference: {{ $verification['difference_label'] ?: '—' }}
            </div>
        </div>
    @endif

    <div class="pg-dealer-ledger-summary">
        <div class="pg-dealer-ledger-card">
            <span>Opening Balance</span>
            <strong>{{ $summary['opening_balance_label'] }}</strong>
            <small style="color:#94A3B8;">As on {{ $start }}</small>
        </div>
        <div class="pg-dealer-ledger-card">
            <span>Total Debit</span>
            <strong>{{ IndianCurrency::formatExact($summary['total_debit']) }}</strong>
        </div>
        <div class="pg-dealer-ledger-card">
            <span>Total Credit</span>
            <strong>{{ IndianCurrency::formatExact($summary['total_credit']) }}</strong>
        </div>
        <div class="pg-dealer-ledger-card is-outstanding">
            <span>Current Outstanding</span>
            <strong>{{ $summary['current_outstanding_label'] }}</strong>
        </div>
    </div>

    <div class="pg-dealer-ledger-table-wrap">
        <table class="pg-dealer-ledger-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Particulars</th>
                    <th>Voucher Type</th>
                    <th>Voucher No.</th>
                    <th class="num">Debit</th>
                    <th class="num">Credit</th>
                    <th class="num">Balance</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ledger as $entry)
                    <tr>
                        <td>{{ $entry['date'] ? \Illuminate\Support\Carbon::parse($entry['date'])->format('d M Y') : '—' }}</td>
                        <td>{{ $entry['particulars'] }}</td>
                        <td>{{ $entry['voucher_type'] ?: '—' }}</td>
                        <td>{{ $entry['voucher_no'] ?: '—' }}</td>
                        <td class="num">{{ (float) $entry['debit'] > 0 ? IndianCurrency::formatExact($entry['debit']) : '—' }}</td>
                        <td class="num">{{ (float) $entry['credit'] > 0 ? IndianCurrency::formatExact($entry['credit']) : '—' }}</td>
                        <td class="num"><strong>{{ IndianCurrency::formatDrCr($entry['balance_signed']) }}</strong></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="pg-dealer-ledger-empty">No Tally ledger entries yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
