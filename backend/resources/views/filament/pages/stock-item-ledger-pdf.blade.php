<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Item Stock Ledger — {{ $header['item_name'] }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #111111;
            margin: 0;
            padding: 8px;
            background: #ffffff;
        }
        .company {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin: 0 0 2px;
        }
        .heading {
            text-align: center;
            margin-bottom: 8px;
            line-height: 1.35;
        }
        .heading .title { font-size: 13px; font-weight: bold; margin: 0; }
        .heading .name { font-size: 11px; font-weight: bold; margin: 2px 0 0; }
        .heading .subtitle { font-size: 10px; font-weight: bold; margin: 2px 0 0; }
        .heading .dates { font-size: 9px; margin: 2px 0 0; }
        .heading .meta { font-size: 8px; margin: 3px 0 0; color: #333333; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
        }
        th, td {
            border: 1px solid #333333;
            padding: 3px 4px;
            vertical-align: middle;
            line-height: 1.25;
        }
        th {
            background: #eeeeee;
            text-align: left;
            font-weight: bold;
            white-space: nowrap;
            font-size: 8px;
        }
        th.num, td.num {
            text-align: right;
            white-space: nowrap;
        }
        .opening { background: #f8fafc; font-weight: bold; }
        .closing { font-weight: bold; }
        .closing td { border-top: 2px solid #111111; }
    </style>
</head>
<body>
    @php
        $blank = '-';
        $fromLabel = \Illuminate\Support\Carbon::parse($header['from'])->format('d-m-Y');
        $toLabel = \Illuminate\Support\Carbon::parse($header['to'])->format('d-m-Y');
        $fmtQty = function ($v) use ($blank) {
            if ($v === null || $v === '') {
                return $blank;
            }

            return number_format((float) $v, 3, '.', ',');
        };
        $fmtRate = function ($v) use ($blank) {
            if ($v === null || $v === '') {
                return $blank;
            }

            return 'Rs.'.number_format((float) $v, 2, '.', ',');
        };
        $fmtMoney = function ($v) use ($blank) {
            if ($v === null || $v === '') {
                return $blank;
            }

            return 'Rs.'.number_format((float) $v, 2, '.', ',');
        };
        $ledgerRows = is_array($rows ?? null) ? $rows : [];
    @endphp

    @if (filled($companyName ?? null))
        <p class="company">{{ $companyName }}</p>
    @endif

    <div class="heading">
        <p class="title">Item Stock Ledger</p>
        <p class="name">{{ $header['item_name'] ?? '' }}</p>
        <p class="subtitle">Stock Ledger</p>
        <p class="dates">{{ $fromLabel }} to {{ $toLabel }}</p>
        <p class="meta">Code: {{ $header['item_code'] ?? '' }} | Unit: {{ $header['unit'] ?? '' }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 8%;">Date</th>
                <th style="width: 18%;">Particulars</th>
                <th style="width: 12%;">Voucher / Ref. No.</th>
                <th class="num" style="width: 9%;">Inward Quantity</th>
                <th class="num" style="width: 9%;">Inward Value</th>
                <th class="num" style="width: 9%;">Outward Quantity</th>
                <th class="num" style="width: 9%;">Outward Value</th>
                <th class="num" style="width: 9%;">Closing Quantity</th>
                <th class="num" style="width: 9%;">Average Purchase Rate</th>
                <th class="num" style="width: 8%;">Closing Value</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($ledgerRows as $row)
                <tr class="{{ ($row['row_type'] ?? '') === 'opening' ? 'opening' : '' }}">
                    <td>{{ ($row['row_type'] ?? '') === 'opening' ? $fromLabel : ($row['date'] ?? '') }}</td>
                    <td>{{ $row['particulars'] ?? '' }}</td>
                    <td>{{ filled($row['voucher_no'] ?? null) ? $row['voucher_no'] : $blank }}</td>
                    <td class="num">{{ ($row['row_type'] ?? '') === 'opening' ? $blank : $fmtQty($row['inward_qty'] ?? null) }}</td>
                    <td class="num">{{ ($row['row_type'] ?? '') === 'opening' ? $blank : $fmtMoney($row['inward_value'] ?? null) }}</td>
                    <td class="num">{{ ($row['row_type'] ?? '') === 'opening' ? $blank : $fmtQty($row['outward_qty'] ?? null) }}</td>
                    <td class="num">{{ ($row['row_type'] ?? '') === 'opening' ? $blank : $fmtMoney($row['outward_value'] ?? null) }}</td>
                    <td class="num">{{ number_format((float) ($row['closing_qty'] ?? 0), 3, '.', ',') }}</td>
                    <td class="num">{{ $fmtRate($row['closing_rate'] ?? 0) }}</td>
                    <td class="num">{{ $fmtMoney($row['closing_value'] ?? 0) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">No stock ledger transactions found for the selected item and date range.</td>
                </tr>
            @endforelse
            <tr class="closing">
                <td></td>
                <td>Closing Balance</td>
                <td></td>
                <td class="num">{{ number_format((float) ($totals['total_inward_qty'] ?? 0), 3, '.', ',') }}</td>
                <td class="num">Rs.{{ number_format((float) ($totals['total_inward_value'] ?? 0), 2, '.', ',') }}</td>
                <td class="num">{{ number_format((float) ($totals['total_outward_qty'] ?? 0), 3, '.', ',') }}</td>
                <td class="num">Rs.{{ number_format((float) ($totals['total_outward_value'] ?? 0), 2, '.', ',') }}</td>
                <td class="num">{{ number_format((float) ($totals['closing_qty'] ?? 0), 3, '.', ',') }}</td>
                <td class="num">Rs.{{ number_format((float) ($totals['closing_rate'] ?? 0), 2, '.', ',') }}</td>
                <td class="num">Rs.{{ number_format((float) ($totals['closing_value'] ?? 0), 2, '.', ',') }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
