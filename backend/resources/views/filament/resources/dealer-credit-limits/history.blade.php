@php
    use App\Support\IndianCurrency;
@endphp

<div class="pg-dealer-ledger-table-wrap" style="overflow-x:auto;">
    <table class="pg-dealer-ledger-table" style="width:100%; border-collapse:collapse;">
        <thead>
            <tr>
                <th style="text-align:left; padding:0.4rem;">When</th>
                <th style="text-align:left; padding:0.4rem;">Action</th>
                <th style="text-align:left; padding:0.4rem;">Previous Base</th>
                <th style="text-align:left; padding:0.4rem;">New Base</th>
                <th style="text-align:left; padding:0.4rem;">Extension</th>
                <th style="text-align:left; padding:0.4rem;">Effective</th>
                <th style="text-align:left; padding:0.4rem;">Valid Until</th>
                <th style="text-align:left; padding:0.4rem;">Remark</th>
                <th style="text-align:left; padding:0.4rem;">Changed By</th>
                <th style="text-align:left; padding:0.4rem;">Role</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($audits as $audit)
                <tr>
                    <td style="padding:0.4rem;">{{ $audit->created_at?->timezone('Asia/Kolkata')->format('d M Y H:i') }}</td>
                    <td style="padding:0.4rem;">{{ ucfirst(str_replace('_', ' ', $audit->action)) }}</td>
                    <td style="padding:0.4rem;">{{ $audit->previous_base !== null ? IndianCurrency::format((float) $audit->previous_base) : '—' }}</td>
                    <td style="padding:0.4rem;">{{ $audit->new_base !== null ? IndianCurrency::format((float) $audit->new_base) : '—' }}</td>
                    <td style="padding:0.4rem;">{{ $audit->extension_amount !== null ? IndianCurrency::format((float) $audit->extension_amount) : '—' }}</td>
                    <td style="padding:0.4rem;">{{ $audit->effective_limit !== null ? IndianCurrency::format((float) $audit->effective_limit) : '—' }}</td>
                    <td style="padding:0.4rem;">{{ $audit->valid_until?->format('d M Y') ?? '—' }}</td>
                    <td style="padding:0.4rem;">{{ $audit->remark ?: '—' }}</td>
                    <td style="padding:0.4rem;">{{ $audit->changedBy?->name ?? ($audit->changed_by_role === 'system' ? 'System' : '—') }}</td>
                    <td style="padding:0.4rem;">{{ ucfirst($audit->changed_by_role) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" style="padding:0.75rem;">No credit limit history yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
