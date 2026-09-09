@php
    use App\Models\Order;
    use App\Services\Orders\FinishedProductOrderAvailabilityService;

    /** @var Order $record */
    $availability = app(FinishedProductOrderAvailabilityService::class)->attachToPayload([], $record);
    $applies = ($availability['stock_availability_applies'] ?? false) === true;
    $rows = is_array($availability['stock_availability'] ?? null) ? $availability['stock_availability'] : [];
    $formatQty = static function (mixed $value, string $unit = 'Nos'): string {
        $qty = (float) $value;
        if (abs($qty - round($qty)) < 0.0001) {
            $text = (string) (int) round($qty);
        } else {
            $text = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
        }

        return $text.' '.$unit;
    };
@endphp

@if ($applies && $rows !== [])
    <div class="pg-stock-avail">
        <style>
            .pg-stock-avail { width: 100%; }
            .pg-stock-avail__intro {
                margin: 0 0 12px;
                font-size: 12px;
                color: #64748B;
                line-height: 1.45;
            }
            .pg-stock-avail__card {
                border: 1px solid #E2E8F0;
                border-radius: 0.75rem;
                padding: 14px 16px;
                margin-bottom: 12px;
                background: #fff;
            }
            .pg-stock-avail__card:last-child { margin-bottom: 0; }
            .pg-stock-avail__head {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                align-items: flex-start;
                margin-bottom: 10px;
            }
            .pg-stock-avail__name { margin: 0; font-weight: 700; color: #0F172A; }
            .pg-stock-avail__code { margin: 2px 0 0; font-size: 12px; color: #94A3B8; }
            .pg-stock-avail__badge {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                padding: 2px 10px;
                font-size: 11px;
                font-weight: 700;
                white-space: nowrap;
            }
            .pg-stock-avail__badge--available { background: #DCFCE7; color: #166534; }
            .pg-stock-avail__badge--short { background: #FEE2E2; color: #991B1B; }
            .pg-stock-avail__row {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                padding: 3px 0;
                font-size: 13px;
            }
            .pg-stock-avail__label { color: #64748B; }
            .pg-stock-avail__value { font-weight: 700; color: #0F172A; font-variant-numeric: tabular-nums; }
            .pg-stock-avail__value--short { color: #B91C1C; }
        </style>
        <p class="pg-stock-avail__intro">
            Oldest active orders waiting for dispatch are allocated first by order date, then order ID.
            Dispatched, rejected, cancelled, and reverted orders do not hold stock.
        </p>
        @foreach ($rows as $row)
            @php
                $unit = trim((string) ($row['unit'] ?? 'Nos')) ?: 'Nos';
                $isShort = ((float) ($row['short_qty'] ?? 0)) > 0.0001;
            @endphp
            <div class="pg-stock-avail__card">
                <div class="pg-stock-avail__head">
                    <div>
                        <p class="pg-stock-avail__name">{{ $row['product_name'] ?? 'Product' }}</p>
                        @if (filled($row['product_code'] ?? null))
                            <p class="pg-stock-avail__code">{{ $row['product_code'] }}</p>
                        @endif
                    </div>
                    <span class="pg-stock-avail__badge pg-stock-avail__badge--{{ $isShort ? 'short' : 'available' }}">
                        {{ $row['stock_status_label'] ?? ($isShort ? 'Short' : 'Available') }}
                    </span>
                </div>
                <div class="pg-stock-avail__row">
                    <span class="pg-stock-avail__label">Order Qty</span>
                    <span class="pg-stock-avail__value">{{ $formatQty($row['order_qty'] ?? 0, $unit) }}</span>
                </div>
                <div class="pg-stock-avail__row">
                    <span class="pg-stock-avail__label">Current Finished Stock</span>
                    <span class="pg-stock-avail__value">{{ $formatQty($row['current_finished_stock'] ?? 0, $unit) }}</span>
                </div>
                <div class="pg-stock-avail__row">
                    <span class="pg-stock-avail__label">Allocated to Earlier Orders</span>
                    <span class="pg-stock-avail__value">{{ $formatQty($row['allocated_to_earlier_orders'] ?? 0, $unit) }}</span>
                </div>
                <div class="pg-stock-avail__row">
                    <span class="pg-stock-avail__label">Remaining Stock Before This Order</span>
                    <span class="pg-stock-avail__value">{{ $formatQty($row['remaining_before_this_order'] ?? $row['available_for_this_order'] ?? 0, $unit) }}</span>
                </div>
                <div class="pg-stock-avail__row">
                    <span class="pg-stock-avail__label">Allocated to This Order</span>
                    <span class="pg-stock-avail__value">{{ $formatQty($row['allocated_to_this_order'] ?? 0, $unit) }}</span>
                </div>
                <div class="pg-stock-avail__row">
                    <span class="pg-stock-avail__label">Short Qty</span>
                    <span class="pg-stock-avail__value {{ $isShort ? 'pg-stock-avail__value--short' : '' }}">{{ $formatQty($row['short_qty'] ?? 0, $unit) }}</span>
                </div>
            </div>
        @endforeach
    </div>
@endif
