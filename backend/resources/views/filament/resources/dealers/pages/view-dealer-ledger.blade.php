@php
    use App\Support\IndianCurrency;

    $payload = $this->ledgerPayload();
    $summary = $payload['summary'];
    $ledger = $payload['ledger'];
    $asOn = $summary['opening_balance_date']
        ? \Illuminate\Support\Carbon::parse($summary['opening_balance_date'])->format('d M Y')
        : '—';
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
            .pg-dealer-ledger-summary {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
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
        .pg-dealer-ledger-card.is-outstanding strong {
            color: #991B1B;
        }
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
        .pg-dealer-ledger-table tr:last-child td {
            border-bottom: 0;
        }
        .pg-dealer-ledger-empty {
            padding: 1.5rem;
            text-align: center;
            color: #64748B;
        }
    </style>

    <div class="pg-dealer-ledger-summary">
        <div class="pg-dealer-ledger-card">
            <span>Opening Balance</span>
            <strong>{{ IndianCurrency::format($summary['opening_balance']) }}</strong>
            <small style="color:#94A3B8;">As on {{ $asOn }}</small>
        </div>
        <div class="pg-dealer-ledger-card">
            <span>Billed Sales</span>
            <strong>{{ IndianCurrency::format($summary['billed_sales']) }}</strong>
        </div>
        <div class="pg-dealer-ledger-card">
            <span>Collections Received</span>
            <strong>{{ IndianCurrency::format($summary['collections_received']) }}</strong>
        </div>
        <div class="pg-dealer-ledger-card is-outstanding">
            <span>Current Outstanding</span>
            <strong>{{ IndianCurrency::format($summary['current_outstanding']) }}</strong>
        </div>
    </div>

    <div class="pg-dealer-ledger-table-wrap">
        <table class="pg-dealer-ledger-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Particulars</th>
                    <th>Reference</th>
                    <th class="num">Debit</th>
                    <th class="num">Credit</th>
                    <th class="num">Balance</th>
                    <th>Status / Remark</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ledger as $entry)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($entry['date'])->format('d M Y') }}</td>
                        <td>{{ $entry['particulars'] }}</td>
                        <td>{{ $entry['reference'] ?: '—' }}</td>
                        <td class="num">{{ (float) $entry['debit'] > 0 ? IndianCurrency::format($entry['debit']) : '—' }}</td>
                        <td class="num">{{ (float) $entry['credit'] > 0 ? IndianCurrency::format($entry['credit']) : '—' }}</td>
                        <td class="num"><strong>{{ IndianCurrency::format($entry['balance']) }}</strong></td>
                        <td>{{ $entry['status_remark'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="pg-dealer-ledger-empty">No ledger entries yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
