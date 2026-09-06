@php
    use App\Enums\ProductionBatchStatus;
    use App\Models\ProductionBatch;
    use Illuminate\Support\Carbon;

    /** @var ProductionBatch $record */
    /** @var array<string, mixed> $sheet */
    $showCosts = (bool) ($sheet['show_costs'] ?? false);
    $fmtQty = static function (mixed $value): string {
        return number_format((float) $value, 3, '.', ',');
    };
    $fmtMoney = static function (mixed $value): string {
        return '₹'.number_format((float) $value, 2, '.', ',');
    };
    $status = $record->status instanceof ProductionBatchStatus
        ? $record->status
        : ProductionBatchStatus::tryFrom((string) $record->status);
    $badgeClass = match ($status) {
        ProductionBatchStatus::Completed => 'pg-pb-badge--success',
        ProductionBatchStatus::Reversed, ProductionBatchStatus::Cancelled, ProductionBatchStatus::Rejected => 'pg-pb-badge--danger',
        ProductionBatchStatus::InProduction, ProductionBatchStatus::Approved => 'pg-pb-badge--info',
        ProductionBatchStatus::DeviationPendingApproval, ProductionBatchStatus::MaterialChecked => 'pg-pb-badge--warning',
        default => 'pg-pb-badge--gray',
    };
@endphp

@include('filament.resources.production-batches.partials.production-batch-view-styles')

<div class="pg-pb-view">
    <div @class(['pg-pb-top', 'pg-pb-top--no-cost' => ! $showCosts])>
        <section class="pg-pb-card">
            <div class="pg-pb-card__head">
                <h2 class="pg-pb-card__title">Batch Details</h2>
            </div>
            <div class="pg-pb-card__body">
                <dl class="pg-pb-dl">
                    <dt>Batch Number</dt>
                    <dd>{{ $sheet['batch_number'] }}</dd>
                    <dt>Product</dt>
                    <dd>{{ $sheet['product_name'] }}</dd>
                    <dt>BOM Number</dt>
                    <dd>{{ $sheet['bom_number'] }}</dd>
                    <dt>Status</dt>
                    <dd><span class="pg-pb-badge {{ $badgeClass }}">{{ $sheet['status_label'] }}</span></dd>
                    <dt>Production Date</dt>
                    <dd>{{ $sheet['production_date'] }}</dd>
                    <dt>Posted By</dt>
                    <dd>{{ $sheet['prepared_by'] }}</dd>
                    @if ($record->completed_at)
                        <dt>Completed</dt>
                        <dd>{{ Carbon::parse($record->completed_at)->timezone('Asia/Kolkata')->format('d M Y, h:i A') }}</dd>
                    @endif
                </dl>
            </div>
        </section>

        <section class="pg-pb-card">
            <div class="pg-pb-card__head">
                <h2 class="pg-pb-card__title">Quantities</h2>
            </div>
            <div class="pg-pb-card__body">
                <dl class="pg-pb-dl">
                    <dt>BOM Quantity</dt>
                    <dd>{{ $record->bom?->formulaQuantityLabel() ?: '—' }}</dd>
                    <dt>Production Quantity</dt>
                    <dd>{{ $fmtQty($sheet['production_quantity']) }} {{ $sheet['production_unit'] }}</dd>
                    <dt>Finished Packs</dt>
                    <dd>{{ $fmtQty($sheet['finished_packs']) }}</dd>
                </dl>
            </div>
        </section>

        @if ($showCosts)
            <section class="pg-pb-card">
                <div class="pg-pb-card__head">
                    <h2 class="pg-pb-card__title">Costing</h2>
                </div>
                <div class="pg-pb-card__body">
                    <dl class="pg-pb-dl">
                        <dt>Material Cost</dt>
                        <dd>{{ $fmtMoney($sheet['material_cost']) }}</dd>
                        <dt>Packaging Cost</dt>
                        <dd>{{ $fmtMoney($sheet['packaging_cost']) }}</dd>
                        @if ($sheet['has_conversion_cost'])
                            <dt>Conversion Cost</dt>
                            <dd>{{ $fmtMoney($sheet['conversion_cost']) }}</dd>
                        @endif
                        <dt>Total Batch Cost</dt>
                        <dd>{{ $fmtMoney($sheet['total_batch_cost']) }}</dd>
                        <dt>Cost / Unit</dt>
                        <dd>{{ $fmtMoney($sheet['cost_per_unit']) }}</dd>
                        <dt>Cost / Pack</dt>
                        <dd>{{ $fmtMoney($sheet['cost_per_pack']) }}</dd>
                    </dl>
                </div>
            </section>
        @endif
    </div>

    <section class="pg-pb-card">
        <div class="pg-pb-card__head">
            <h2 class="pg-pb-card__title">Material Consumption</h2>
        </div>
        <div class="pg-pb-table-wrap">
            <table class="pg-pb-table">
                <colgroup>
                    <col class="col-material">
                    <col class="col-qty">
                    <col class="col-qty">
                    <col class="col-uom">
                    @if ($showCosts)
                        <col class="col-rate">
                        <col class="col-cost">
                    @endif
                </colgroup>
                <thead>
                    <tr>
                        <th>Material</th>
                        <th class="num">Required Qty</th>
                        <th class="num">Actual Consumed Qty</th>
                        <th>UOM</th>
                        @if ($showCosts)
                            <th class="num">Rate</th>
                            <th class="num">Material Cost</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sheet['materials'] as $row)
                        <tr>
                            <td class="material">{{ $row['material_name'] }}</td>
                            <td class="num">{{ $fmtQty($row['required_qty']) }}</td>
                            <td class="num">{{ $fmtQty($row['actual_qty']) }}</td>
                            <td>{{ $row['uom'] }}</td>
                            @if ($showCosts)
                                <td class="num">{{ $fmtMoney($row['rate']) }}</td>
                                <td class="num">{{ $fmtMoney($row['cost']) }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td class="pg-pb-empty" colspan="{{ $showCosts ? 6 : 4 }}">No material consumption recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($showCosts && count($sheet['materials']) > 0)
                    <tfoot>
                        <tr class="pg-pb-total">
                            <td colspan="5" class="num">Total Material Cost</td>
                            <td class="num">{{ $fmtMoney(collect($sheet['materials'])->sum('cost')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    @if ($record->isFinishedProductStockPosted())
        <section class="pg-pb-card">
            <div class="pg-pb-card__head">
                <h2 class="pg-pb-card__title">Finished Product Stock Posting</h2>
            </div>
            <div class="pg-pb-card__body">
                <div @class(['pg-pb-summary', 'pg-pb-summary--no-cost' => ! $showCosts])>
                    <div class="pg-pb-summary__item">
                        <span class="pg-pb-summary__label">Stock Posted</span>
                        <span class="pg-pb-summary__value"><span class="pg-pb-badge pg-pb-badge--success">Yes</span></span>
                    </div>
                    <div class="pg-pb-summary__item">
                        <span class="pg-pb-summary__label">Quantity Added</span>
                        <span class="pg-pb-summary__value">{{ $fmtQty($record->actual_output_quantity) }}</span>
                    </div>
                    @if ($showCosts)
                        <div class="pg-pb-summary__item">
                            <span class="pg-pb-summary__label">Avg Production Cost</span>
                            <span class="pg-pb-summary__value">{{ $fmtMoney($record->cost_per_unit) }}</span>
                        </div>
                    @endif
                    <div class="pg-pb-summary__item">
                        <span class="pg-pb-summary__label">Stock Before</span>
                        <span class="pg-pb-summary__value">{{ $record->finished_stock_before !== null ? $fmtQty($record->finished_stock_before) : '—' }}</span>
                    </div>
                    <div class="pg-pb-summary__item">
                        <span class="pg-pb-summary__label">Stock After</span>
                        <span class="pg-pb-summary__value">{{ $record->finished_stock_after !== null ? $fmtQty($record->finished_stock_after) : '—' }}</span>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if (filled($record->notes) || filled($record->reversal_reason))
        <section class="pg-pb-card">
            <div class="pg-pb-card__head">
                <h2 class="pg-pb-card__title">Notes</h2>
            </div>
            <div class="pg-pb-card__body">
                @if (filled($record->notes))
                    <p class="pg-pb-muted"><strong>Remarks:</strong> {{ $record->notes }}</p>
                @endif
                @if (filled($record->reversal_reason))
                    <p class="pg-pb-muted" style="margin-top: 0.35rem;"><strong>Reversal Reason:</strong> {{ $record->reversal_reason }}</p>
                @endif
            </div>
        </section>
    @endif
</div>
