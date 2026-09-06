<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Production Batch Sheet — {{ $sheet['batch_number'] }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            font-size: 11px;
            color: #111;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .sheet { width: 100%; }
        .heading {
            text-align: center;
            border-bottom: 2px solid #111;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .heading .company { margin: 0; font-size: 13px; font-weight: 700; }
        .heading .title { margin: 2px 0 0; font-size: 16px; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
        .heading .note { margin: 4px 0 0; font-size: 10px; color: #333; }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .meta td {
            border: 1px solid #333;
            padding: 4px 6px;
            vertical-align: top;
            width: 50%;
        }
        .meta .label { font-size: 9px; color: #444; text-transform: uppercase; letter-spacing: 0.03em; }
        .meta .value { font-size: 12px; font-weight: 700; margin-top: 1px; }
        table.materials {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 10px;
        }
        table.materials th,
        table.materials td {
            border: 1px solid #333;
            padding: 4px 5px;
            vertical-align: middle;
        }
        table.materials th {
            background: #eee;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            text-align: left;
        }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            margin: 0 0 4px;
            letter-spacing: 0.04em;
        }
        .costs {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .costs td {
            border: 1px solid #333;
            padding: 4px 6px;
        }
        .costs .label { width: 70%; }
        .costs .value { width: 30%; text-align: right; font-weight: 700; }
        .costs .grand td { font-weight: 800; background: #f3f3f3; }
        .remarks {
            border: 1px solid #333;
            min-height: 42px;
            padding: 6px;
            margin-bottom: 12px;
        }
        .sign {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }
        .sign td {
            width: 50%;
            vertical-align: top;
            padding: 0 10px 0 0;
        }
        .sign .line {
            border-top: 1px solid #111;
            margin-top: 42px;
            padding-top: 4px;
            font-size: 10px;
        }
        .actions { margin-bottom: 10px; }
        @media print {
            .actions { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    @php
        $fmtQty = static fn (mixed $v): string => number_format((float) $v, 3, '.', ',');
        $fmtMoney = static fn (mixed $v): string => '₹'.number_format((float) $v, 2, '.', ',');
        $showCosts = (bool) ($showCosts ?? false);
    @endphp

    <div class="actions">
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    <div class="sheet">
        <div class="heading">
            <p class="company">{{ $companyName }}</p>
            <p class="title">Production Batch Sheet</p>
            <p class="note">Use the Actual Qty to Use below. Manufacture this batch exactly as confirmed.</p>
        </div>

        <table class="meta">
            <tr>
                <td>
                    <div class="label">Batch Number</div>
                    <div class="value">{{ $sheet['batch_number'] }}</div>
                </td>
                <td>
                    <div class="label">Production Date</div>
                    <div class="value">{{ $sheet['production_date'] }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">Product Name</div>
                    <div class="value">{{ $sheet['product_name'] }}</div>
                </td>
                <td>
                    <div class="label">BOM Number</div>
                    <div class="value">{{ $sheet['bom_number'] }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">Production Quantity</div>
                    <div class="value">{{ $fmtQty($sheet['production_quantity']) }} {{ $sheet['production_unit'] }}</div>
                </td>
                <td>
                    <div class="label">Finished Packs</div>
                    <div class="value">{{ $fmtQty($sheet['finished_packs']) }}</div>
                </td>
            </tr>
        </table>

        <p class="section-title">Material Consumption Instructions</p>
        <table class="materials">
            <thead>
                <tr>
                    <th>Material Name</th>
                    <th class="num">Required Qty</th>
                    <th class="num">Actual Qty to Use</th>
                    <th>UOM</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sheet['materials'] as $row)
                    <tr>
                        <td>{{ $row['material_name'] }}</td>
                        <td class="num">{{ $fmtQty($row['required_qty']) }}</td>
                        <td class="num">{{ $fmtQty($row['actual_qty']) }}</td>
                        <td>{{ $row['uom'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No materials recorded for this batch.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($showCosts)
            <p class="section-title">Cost Summary</p>
            <table class="costs">
                <tr>
                    <td class="label">Material Cost</td>
                    <td class="value">{{ $fmtMoney($sheet['material_cost']) }}</td>
                </tr>
                <tr>
                    <td class="label">Packaging Cost</td>
                    <td class="value">{{ $fmtMoney($sheet['packaging_cost']) }}</td>
                </tr>
                @if ($sheet['has_conversion_cost'])
                    <tr>
                        <td class="label">Manufacturing / Conversion Cost</td>
                        <td class="value">{{ $fmtMoney($sheet['conversion_cost']) }}</td>
                    </tr>
                @endif
                <tr class="grand">
                    <td class="label">Total Batch Cost</td>
                    <td class="value">{{ $fmtMoney($sheet['total_batch_cost']) }}</td>
                </tr>
                <tr>
                    <td class="label">Cost per Pack / Unit</td>
                    <td class="value">{{ $fmtMoney($sheet['cost_per_pack']) }}</td>
                </tr>
            </table>
        @endif

        <p class="section-title">Remarks</p>
        <div class="remarks">{{ $sheet['remarks'] }}</div>

        <table class="sign">
            <tr>
                <td>
                    <div class="label" style="font-size:9px;text-transform:uppercase;letter-spacing:0.03em;color:#444;">Prepared By</div>
                    <div style="font-weight:700;margin-top:4px;">{{ $sheet['prepared_by'] }}</div>
                    <div class="line">Name / Date</div>
                </td>
                <td>
                    <div class="label" style="font-size:9px;text-transform:uppercase;letter-spacing:0.03em;color:#444;">Production Supervisor</div>
                    <div class="line">Signature / Date</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
