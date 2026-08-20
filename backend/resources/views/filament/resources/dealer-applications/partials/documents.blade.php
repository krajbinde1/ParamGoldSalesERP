@php
    /** @var \App\Models\DealerApplication $application */
    $slots = $slots ?? [];
@endphp

<ul class="pr-docs-list divide-y divide-gray-200 dark:divide-white/10" style="margin:0;padding:0;list-style:none;">
    @foreach ($slots as $slot)
        @php
            $uploaded = (bool) ($slot['uploaded'] ?? false);
            $isPdf = (bool) ($slot['is_pdf'] ?? false);
            $viewUrl = null;
            if ($uploaded && ! empty($slot['id'])) {
                $viewParams = [
                    'dealerApplication' => $application->id,
                    'dealerApplicationDocument' => $slot['id'],
                ];
                $viewUrl = \Illuminate\Support\Facades\Route::has('filament.admin.dealer-applications.documents.show')
                    ? route('filament.admin.dealer-applications.documents.show', $viewParams)
                    : route('dealer-applications.documents.show', $viewParams);
            }
        @endphp
        <li style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;flex-wrap:wrap;">
            <div style="min-width:0;flex:1;">
                <div style="font-size:13px;font-weight:600;">{{ $slot['document_name'] }}</div>
                <div style="margin-top:2px;font-size:12px;color:#6b7280;">
                    @if ($uploaded)
                        Uploaded
                        @if (! empty($slot['original_filename']))
                            • {{ $slot['original_filename'] }}
                        @endif
                    @else
                        Not uploaded
                    @endif
                </div>
            </div>
            @if ($viewUrl)
                <a
                    href="{{ $viewUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:inline-flex;align-items:center;gap:6px;flex-shrink:0;border:1px solid #e5e7eb;border-radius:8px;background:#fff;padding:6px 10px;font-size:13px;font-weight:600;color:#374151;text-decoration:none;"
                >
                    View {{ $isPdf ? 'PDF' : 'Document' }}
                </a>
            @endif
        </li>
    @endforeach
</ul>
