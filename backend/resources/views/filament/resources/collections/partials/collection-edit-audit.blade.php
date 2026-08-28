@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\CollectionAudit> $audits */
    $audits = $audits ?? collect();
@endphp

<div class="pg-collection-edit-audit">
    <style>
        .pg-collection-edit-audit { width: 100%; }
        .pg-collection-edit-audit__card {
            margin: 0 0 14px;
            padding: 12px 14px;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            background: #F8FAFC;
        }
        .pg-collection-edit-audit__card:last-child { margin-bottom: 0; }
        .pg-collection-edit-audit__meta {
            margin: 0 0 4px;
            font-size: 12px;
            color: #475569;
            line-height: 1.45;
        }
        .pg-collection-edit-audit table {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
            font-size: 12px;
        }
        .pg-collection-edit-audit th,
        .pg-collection-edit-audit td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid #E2E8F0;
        }
        .pg-collection-edit-audit th {
            color: #64748B;
            font-weight: 650;
            background: #F1F5F9;
        }
        .pg-collection-edit-audit td { color: #0F172A; }
    </style>

    @foreach ($audits as $audit)
        <div class="pg-collection-edit-audit__card">
            <p class="pg-collection-edit-audit__meta">Changed by: {{ $audit->changedByUser?->name ?: '—' }}</p>
            <p class="pg-collection-edit-audit__meta">Date and time: {{ $audit->formattedChangedAt() }}</p>

            <table>
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Old value</th>
                        <th>New value</th>
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
</div>
