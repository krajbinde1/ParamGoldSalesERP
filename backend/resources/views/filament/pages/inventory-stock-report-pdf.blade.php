<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Inventory Stock Report</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #111111;
            margin: 0;
            padding: 8px 10px 22px;
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
        .heading .meta { font-size: 8px; margin: 3px 0 0; color: #333333; }
        .heading .filters { font-size: 8px; margin: 2px 0 0; color: #333333; }
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
        td.name {
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 180px;
        }
        .empty {
            text-align: center;
            padding: 12px 4px;
            font-style: italic;
        }
        .totals {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .totals td {
            border: 1px solid #333333;
            padding: 4px 6px;
        }
        .totals .label { text-align: right; font-weight: bold; width: 78%; }
        .totals .value { text-align: right; white-space: nowrap; width: 22%; }
        .totals .grand .label,
        .totals .grand .value { font-weight: bold; }
    </style>
</head>
<body>
    @php
        $fmtQty = static function ($v): string {
            if ($v === null || $v === '') {
                return '—';
            }
            $n = (float) $v;
            if (abs($n - round($n)) < 0.0000001) {
                return number_format($n, 0, '.', ',');
            }

            return rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.');
        };
        $fmtMoney = static function ($v): string {
            if ($v === null || $v === '') {
                return '—';
            }

            return '₹'.number_format((float) $v, 2, '.', ',');
        };
        $reportRows = is_array($rows ?? null) ? $rows : [];
        $totalLines = is_array($totals ?? null) ? $totals : [];
        $showValue = (bool) ($showCosts ?? true);
        $colspan = $showValue ? 8 : 7;
    @endphp

    @if (filled($companyName ?? null))
        <p class="company">{{ $companyName }}</p>
    @endif

    <div class="heading">
        <p class="title">Inventory Stock Report</p>
        <p class="meta">Generated: {{ $generatedAt ?? '' }}</p>
        <p class="meta">Inventory Type: {{ $inventoryTypeLabel ?? 'All' }}</p>
        @if (! empty($appliedFilters))
            <p class="filters">Filters: {{ implode(' | ', $appliedFilters) }}</p>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th class="num" style="width: 5%;">Sr. No.</th>
                <th style="width: {{ $showValue ? '22%' : '26%' }};">Material / Item Name</th>
                <th style="width: 12%;">Item Code</th>
                <th style="width: 14%;">Inventory Type</th>
                <th class="num" style="width: 10%;">Available Qty</th>
                <th style="width: 8%;">Unit</th>
                @if ($showValue)
                    <th class="num" style="width: 12%;">Stock Value</th>
                @endif
                <th style="width: 9%;">Stock Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reportRows as $row)
                <tr>
                    <td class="num">{{ $row['sr_no'] ?? '' }}</td>
                    <td class="name">{{ $row['name'] ?? '' }}</td>
                    <td>{{ $row['code'] ?? '—' }}</td>
                    <td>{{ $row['inventory_type'] ?? '' }}</td>
                    <td class="num">{{ $fmtQty($row['available_quantity'] ?? null) }}</td>
                    <td>{{ $row['unit'] ?? '' }}</td>
                    @if ($showValue)
                        <td class="num">{{ $fmtMoney($row['stock_value'] ?? null) }}</td>
                    @endif
                    <td>{{ $row['stock_status_label'] ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ $colspan }}">
                        No inventory records found for the selected filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($showValue && $totalLines !== [])
        <table class="totals">
            <tbody>
                @foreach ($totalLines as $line)
                    <tr @class(['grand' => ! empty($line['bold'])])>
                        <td class="label">{{ $line['label'] ?? '' }}</td>
                        <td class="value">{{ $fmtMoney($line['value'] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont('DejaVu Sans');
            $size = 8;
            $text = 'Page {PAGE_NUM} of {PAGE_COUNT}';
            $width = $fontMetrics->getTextWidth($text, $font, $size);
            $x = $pdf->get_width() - 28 - $width;
            $y = $pdf->get_height() - 18;
            $pdf->page_text($x, $y, $text, $font, $size, [0.2, 0.2, 0.2]);
        }
    </script>
</body>
</html>
