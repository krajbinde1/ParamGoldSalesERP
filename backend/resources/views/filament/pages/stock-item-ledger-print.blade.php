<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Stock Ledger — {{ $header['item_name'] }}</title>
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
        .heading {
            text-align: center;
            margin-bottom: 10px;
            line-height: 1.35;
        }
        .heading .name { font-size: 15px; font-weight: 700; margin: 0; }
        .heading .title { font-size: 12px; font-weight: 600; margin: 2px 0 0; }
        .heading .dates { font-size: 11px; margin: 2px 0 0; }
        .heading .meta { font-size: 10px; margin: 4px 0 0; color: #333; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }
        th, td {
            border: 1px solid #333;
            padding: 2px 4px;
            vertical-align: middle;
            line-height: 1.25;
        }
        th {
            background: #eee;
            text-align: left;
            font-weight: 700;
            white-space: nowrap;
        }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .opening { background: #f8fafc; font-weight: 600; }
        .closing { font-weight: 700; }
        .closing td { border-top: 2px solid #111; }
        .actions { margin-bottom: 12px; }
        @media print {
            .actions { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    @php
        $blank = '—';
        $fromLabel = \Illuminate\Support\Carbon::parse($header['from'])->format('d-m-Y');
        $toLabel = \Illuminate\Support\Carbon::parse($header['to'])->format('d-m-Y');
        $fmtQty = fn ($v) => $v === null || $v === '' ? $blank : number_format((float) $v, 3, '.', ',');
        $fmtRate = fn ($v) => $v === null || $v === '' ? $blank : '₹'.number_format((float) $v, 2, '.', ',');
        $fmtMoney = fn ($v) => $v === null || $v === '' ? $blank : '₹'.number_format((float) $v, 2, '.', ',');
    @endphp

    <div class="actions">
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    <div class="heading">
        <p class="name">{{ $header['item_name'] }}</p>
        <p class="title">Stock Ledger</p>
        <p class="dates">{{ $fromLabel }} to {{ $toLabel }}</p>
        <p class="meta">Code: {{ $header['item_code'] }} · Unit: {{ $header['unit'] }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Particulars</th>
                <th>Voucher / Ref. No.</th>
                <th class="num">Inward Quantity</th>
                <th class="num">Inward Value</th>
                <th class="num">Outward Quantity</th>
                <th class="num">Outward Value</th>
                <th class="num">Closing Quantity</th>
                <th class="num">Average Purchase Rate</th>
                <th class="num">Closing Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ ($row['row_type'] ?? '') === 'opening' ? 'opening' : '' }}">
                    <td>{{ ($row['row_type'] ?? '') === 'opening' ? $fromLabel : $row['date'] }}</td>
                    <td>{{ $row['particulars'] }}</td>
                    <td>{{ filled($row['voucher_no'] ?? null) ? $row['voucher_no'] : (($row['row_type'] ?? '') === 'opening' ? $blank : $blank) }}</td>
                    <td class="num">{{ ($row['row_type'] ?? '') === 'opening' ? $blank : $fmtQty($row['inward_qty'] ?? null) }}</td>
                    <td class="num">{{ ($row['row_type'] ?? '') === 'opening' ? $blank : $fmtMoney($row['inward_value'] ?? null) }}</td>
                    <td class="num">{{ ($row['row_type'] ?? '') === 'opening' ? $blank : $fmtQty($row['outward_qty'] ?? null) }}</td>
                    <td class="num">{{ ($row['row_type'] ?? '') === 'opening' ? $blank : $fmtMoney($row['outward_value'] ?? null) }}</td>
                    <td class="num">{{ number_format((float) $row['closing_qty'], 3, '.', ',') }}</td>
                    <td class="num">{{ $fmtRate($row['closing_rate'] ?? 0) }}</td>
                    <td class="num">{{ $fmtMoney($row['closing_value'] ?? 0) }}</td>
                </tr>
            @endforeach
            <tr class="closing">
                <td></td>
                <td>Closing Balance</td>
                <td></td>
                <td class="num">{{ number_format((float) $totals['total_inward_qty'], 3, '.', ',') }}</td>
                <td class="num">₹{{ number_format((float) $totals['total_inward_value'], 2, '.', ',') }}</td>
                <td class="num">{{ number_format((float) $totals['total_outward_qty'], 3, '.', ',') }}</td>
                <td class="num">₹{{ number_format((float) $totals['total_outward_value'], 2, '.', ',') }}</td>
                <td class="num">{{ number_format((float) $totals['closing_qty'], 3, '.', ',') }}</td>
                <td class="num">₹{{ number_format((float) $totals['closing_rate'], 2, '.', ',') }}</td>
                <td class="num">₹{{ number_format((float) $totals['closing_value'], 2, '.', ',') }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
