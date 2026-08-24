@php
    /** @var list<\App\Models\OrderEditPermissionRequest> $audits */
    $audits = $audits ?? [];
@endphp

<div class="pg-order-edit-audit">
    <style>
        .pg-order-edit-audit { width: 100%; }
        .pg-order-edit-audit__card {
            margin: 0 0 14px;
            padding: 12px 14px;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            background: #F8FAFC;
        }
        .pg-order-edit-audit__card:last-child { margin-bottom: 0; }
        .pg-order-edit-audit__title {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 700;
            color: #0F172A;
        }
        .pg-order-edit-audit__meta {
            margin: 0 0 4px;
            font-size: 12px;
            color: #475569;
            line-height: 1.45;
        }
        .pg-order-edit-audit table {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
            font-size: 12px;
        }
        .pg-order-edit-audit th,
        .pg-order-edit-audit td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid #E2E8F0;
        }
        .pg-order-edit-audit th {
            color: #64748B;
            font-weight: 650;
            background: #F1F5F9;
        }
        .pg-order-edit-audit td { color: #0F172A; }
        .pg-order-edit-audit__empty {
            margin: 0;
            font-size: 13px;
            color: #94A3B8;
        }
    </style>

    @if (count($audits) === 0)
        <p class="pg-order-edit-audit__empty">No transport corrections recorded.</p>
    @else
        @foreach ($audits as $audit)
            <div class="pg-order-edit-audit__card">
                <p class="pg-order-edit-audit__title">Correction {{ $loop->iteration }}</p>
                <p class="pg-order-edit-audit__meta">Edit Reason: {{ $audit->reason ?: '—' }}</p>
                <p class="pg-order-edit-audit__meta">Permission Requested By: {{ $audit->requestedByUser?->name ?: '—' }}</p>
                <p class="pg-order-edit-audit__meta">Approved By Director: {{ $audit->reviewedByUser?->name ?: '—' }}</p>
                <p class="pg-order-edit-audit__meta">Approval Date &amp; Time: {{ $audit->formattedReviewedAt() ?: '—' }}</p>
                <p class="pg-order-edit-audit__meta">Edited By: {{ $audit->editedByUser?->name ?: '—' }}</p>
                <p class="pg-order-edit-audit__meta">Edit Date &amp; Time: {{ $audit->formattedEditedAt() ?: '—' }}</p>

                <table>
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($audit->auditRows() as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td>{{ $row['old'] }}</td>
                                <td>{{ $row['new'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">No field changes recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</div>
