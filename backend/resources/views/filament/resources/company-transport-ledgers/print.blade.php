<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Company Transport Ledger</title>
    <style>
        @page { size: landscape; margin: 10mm; }
        body {
            font-family: "Segoe UI", Tahoma, sans-serif;
            font-size: 11px;
            color: #111;
            margin: 0;
            padding: 12px;
            background: #fff;
        }
        .heading { text-align: center; margin-bottom: 10px; line-height: 1.35; }
        .heading .name { font-size: 15px; font-weight: 700; margin: 0; }
        .heading .title { font-size: 12px; font-weight: 600; margin: 2px 0 0; }
        .heading .meta { font-size: 10px; margin: 4px 0 0; color: #333; }
        .summary { margin: 0 0 10px; display: flex; gap: 16px; }
        .summary span { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 3px 5px; vertical-align: middle; }
        th { background: #eee; text-align: left; font-weight: 700; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .totals td { font-weight: 700; background: #f8fafc; }
        @media print {
            .no-print { display: none; }
        }
    </style>
    @if (!empty($autoPrint))
        <script>window.addEventListener('load', function () { window.print(); });</script>
    @endif
</head>
<body>
    <div class="heading">
        <p class="name">{{ $companyName }}</p>
        <p class="title">Company Transport Ledger</p>
        <p class="meta">Generated {{ $generatedAt }}</p>
    </div>
    <p class="summary">
        <span>Total Transport Collected: {{ $summary['total_collected_label'] }}</span>
        <span>Total Transport Expense: {{ $summary['total_expense_label'] }}</span>
        <span>Current Balance: {{ $summary['current_balance_label'] }}</span>
    </p>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Particulars</th>
                <th>Order No.</th>
                <th>Transport Type</th>
                <th>Vehicle No.</th>
                <th class="num">Debit</th>
                <th class="num">Credit</th>
                <th class="num">Running Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ $entry->transaction_date?->format('d-m-Y') }}</td>
                    <td>{{ $entry->particulars }}</td>
                    <td>{{ $entry->order_no ?: '—' }}</td>
                    <td>{{ $entry->transportTypeLabel() ?: '—' }}</td>
                    <td>{{ $entry->vehicle_number ?: '—' }}</td>
                    <td class="num">{{ (float) $entry->debit_amount > 0.004 ? \App\Support\IndianCurrency::formatExact((float) $entry->debit_amount) : '' }}</td>
                    <td class="num">{{ (float) $entry->credit_amount > 0.004 ? \App\Support\IndianCurrency::formatExact((float) $entry->credit_amount) : '' }}</td>
                    <td class="num">{{ \App\Support\IndianCurrency::formatExact((float) $entry->getAttribute('running_balance')) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">No ledger entries for the selected filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
