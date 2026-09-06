<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title ?? 'Inventory Stock Report' }}</title>
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
        th.center, td.center {
            text-align: center;
        }
        td.name {
            word-wrap: break-word;
            overflow-wrap: break-word;
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
        $safe = static function (mixed $value): string {
            $text = (string) $value;

            return function_exists('mb_scrub') ? mb_scrub($text, 'UTF-8') : $text;
        };
        $fmtMoney = static function (mixed $amount) use ($safe): string {
            if ($amount === null || $amount === '') {
                return '—';
            }

            return $safe('₹'.number_format((float) $amount, 2));
        };
        $reportTitle = $safe($title ?? 'Inventory Stock Report');
        $reportColumns = is_array($columns ?? null) ? $columns : [];
        $reportRows = is_array($rows ?? null) ? $rows : [];
        $totalLines = is_array($totals ?? null) ? $totals : [];
        $colspan = max(count($reportColumns), 1);
    @endphp

    @if (filled($companyName ?? null))
        <p class="company">{{ $safe($companyName) }}</p>
    @endif

    <div class="heading">
        <p class="title">{{ $reportTitle }}</p>
        <p class="meta">Generated on: {{ $safe($generatedAt ?? '') }}</p>
        <p class="meta">Applied Filters: {{ $safe($appliedFiltersLabel ?? 'None') }}</p>
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($reportColumns as $column)
                    @php
                        $align = $column['align'] ?? 'left';
                        $thClass = $align === 'right' ? 'num' : ($align === 'center' ? 'center' : '');
                    @endphp
                    <th @class([$thClass => $thClass !== ''])>{{ $safe($column['label'] ?? '') }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($reportRows as $row)
                <tr>
                    @foreach ($row as $cellIndex => $cell)
                        @php
                            $align = is_array($cell) ? ($cell['align'] ?? 'left') : ($reportColumns[$cellIndex]['align'] ?? 'left');
                            $value = is_array($cell) ? ($cell['value'] ?? '') : $cell;
                            $tdClass = $align === 'right' ? 'num' : ($align === 'center' ? 'center' : 'name');
                        @endphp
                        <td @class([$tdClass])>{{ $safe($value) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ $colspan }}">
                        No stock items match the selected filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($totalLines !== [])
        <table class="totals">
            <tbody>
                @foreach ($totalLines as $line)
                    <tr @class(['grand' => ! empty($line['bold'])])>
                        <td class="label">{{ $safe($line['label'] ?? '') }}</td>
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
