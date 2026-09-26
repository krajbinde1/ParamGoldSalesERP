@php
    /** @var list<array<string, mixed>> $proofs */
    $proofs = $proofs ?? [];
@endphp

@if (count($proofs) === 0)
    <p class="text-sm text-gray-500 dark:text-gray-400">No payment proof attached.</p>
@else
    <ul class="pr-proofs-list divide-y divide-gray-200 dark:divide-white/10" style="margin:0;padding:0;list-style:none;">
        @foreach ($proofs as $proof)
            @php
                $fileName = (string) ($proof['file_name'] ?? 'Payment Proof');
                $sizeLabel = trim((string) ($proof['file_size_label'] ?? ''));
                $isPdf = ! empty($proof['is_pdf']);
                $viewUrl = (string) ($proof['view_url'] ?? '');
                $downloadUrl = (string) ($proof['download_url'] ?? $viewUrl);
            @endphp
            <li style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;flex-wrap:wrap;">
                <div style="display:flex;align-items:flex-start;gap:10px;min-width:0;flex:1;">
                    <span
                        aria-hidden="true"
                        style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;flex-shrink:0;border-radius:6px;{{ $isPdf ? 'background:#fef2f2;color:#dc2626;' : 'background:#f0f9ff;color:#0284c7;' }}"
                    >
                        @if ($isPdf)
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" style="width:16px;height:16px;flex-shrink:0;display:block;" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h6l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 3v5h5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6M9 17h4" />
                            </svg>
                        @else
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" style="width:16px;height:16px;flex-shrink:0;display:block;" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        @endif
                    </span>
                    <div style="min-width:0;flex:1;">
                        <div style="font-size:13px;font-weight:600;color:inherit;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $fileName }}">
                            {{ $fileName }}
                        </div>
                        @if ($sizeLabel !== '')
                            <div style="margin-top:2px;font-size:12px;color:#6b7280;">{{ $sizeLabel }}</div>
                        @endif
                    </div>
                </div>
                <div style="display:inline-flex;align-items:center;gap:8px;flex-shrink:0;">
                    @if ($viewUrl !== '')
                        <a
                            href="{{ $viewUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            style="display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;padding:6px 10px;font-size:13px;font-weight:600;color:#374151;text-decoration:none;"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;flex-shrink:0;display:block;" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            View
                        </a>
                    @endif
                    @if ($downloadUrl !== '')
                        <a
                            href="{{ $downloadUrl }}"
                            rel="noopener noreferrer"
                            style="display:inline-flex;align-items:center;gap:6px;border-radius:8px;background:#0d9488;padding:6px 10px;font-size:13px;font-weight:600;color:#fff;text-decoration:none;"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;flex-shrink:0;display:block;" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            Download
                        </a>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
