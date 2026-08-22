<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Employee Outstanding — {{ $payload['employee_name'] }}</title>
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
    <p class="company">{{ $companyName }}</p>
    <div class="heading">
        <p class="title">Employee Outstanding</p>
        <p class="meta">
            Employee:
            {{ $payload['employee_name'] }}
            @if (filled($payload['employee_code']))
                ({{ $payload['employee_code'] }})
            @endif
        </p>
        <p class="meta">Generated on: {{ $generatedAt }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee Name</th>
                <th>Dealer Code</th>
                <th>Dealer Name</th>
                <th>Village</th>
                <th class="num">Outstanding Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payload['rows'] as $row)
                <tr>
                    <td>{{ $row['employee_name'] }}</td>
                    <td>{{ $row['dealer_code'] }}</td>
                    <td>{{ $row['dealer_name'] }}</td>
                    <td>{{ $row['village'] }}</td>
                    <td class="num">{{ \App\Support\IndianCurrency::format((float) $row['outstanding']) }}</td>
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="5">No dealers assigned to this employee.</td>
                </tr>
            @endforelse
            <tr class="total">
                <td colspan="4" class="num">Total Outstanding</td>
                <td class="num">{{ \App\Support\IndianCurrency::format((float) $payload['total']) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
