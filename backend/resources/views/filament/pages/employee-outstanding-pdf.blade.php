<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Total Outstanding</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #111111;
            margin: 0;
            padding: 12px 14px 20px;
            background: #ffffff;
        }
        .company {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            margin: 0 0 2px;
        }
        .heading {
            text-align: center;
            margin-bottom: 10px;
            line-height: 1.35;
        }
        .heading .title { font-size: 14px; font-weight: bold; margin: 0; }
        .heading .meta { font-size: 9px; margin: 3px 0 0; color: #333333; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
        }
        th, td {
            border: 1px solid #333333;
            padding: 4px 6px;
            vertical-align: middle;
        }
        th {
            background: #eeeeee;
            text-align: left;
            font-weight: bold;
            font-size: 9px;
        }
        th.num, td.num {
            text-align: right;
            white-space: nowrap;
        }
        .total td {
            font-weight: bold;
            background: #f8fafc;
        }
        .empty {
            text-align: center;
            padding: 12px 4px;
            font-style: italic;
        }
    </style>
</head>
<body>
    @php
        $safe = static function (mixed $value): string {
            $text = (string) $value;

            return function_exists('mb_scrub') ? mb_scrub($text, 'UTF-8') : $text;
        };
        $fmtMoney = static function (mixed $amount) use ($safe): string {
            $formatted = str_replace(
                ["\u{20B9}", '₹'],
                'Rs.',
                \App\Support\IndianCurrency::format((float) $amount),
            );

            return $safe($formatted);
        };
        $scope = $safe($payload['scope_label'] ?? $payload['employee_name'] ?? 'All Employees');
    @endphp

    <p class="company">{{ $safe($companyName) }}</p>
    <div class="heading">
        <p class="title">Total Outstanding</p>
        <p class="meta">
            Employee:
            {{ $scope }}
            @if (filled($payload['employee_code'] ?? null))
                ({{ $safe($payload['employee_code']) }})
            @endif
        </p>
        <p class="meta">Generated on: {{ $safe($generatedAt) }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Dealer Code</th>
                <th>Dealer Name</th>
                <th>Village</th>
                <th class="num">Outstanding Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payload['rows'] as $row)
                <tr>
                    <td>{{ $safe($row['employee_name']) }}</td>
                    <td>{{ $safe($row['dealer_code']) }}</td>
                    <td>{{ $safe($row['dealer_name']) }}</td>
                    <td>{{ $safe($row['village']) }}</td>
                    <td class="num">{{ $fmtMoney($row['outstanding']) }}</td>
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="5">No outstanding records for this filter.</td>
                </tr>
            @endforelse
            <tr class="total">
                <td colspan="4" class="num">Total Outstanding</td>
                <td class="num">{{ $fmtMoney($payload['total']) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
