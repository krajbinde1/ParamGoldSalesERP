@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\PaymentRequestSupportingDocument> $documents */
    $documents = $documents ?? collect();
@endphp

@if ($documents->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No supporting documents attached.</p>
@else
    <ul class="divide-y divide-gray-200 dark:divide-white/10 -mx-1">
        @foreach ($documents as $document)
            @php
                $viewParams = [
                    'paymentRequest' => $document->payment_request_id,
                    'supportingDocument' => $document->id,
                ];
                $viewUrl = \Illuminate\Support\Facades\Route::has('filament.admin.payment-requests.supporting-documents.show')
                    ? route('filament.admin.payment-requests.supporting-documents.show', $viewParams)
                    : route('payment-requests.supporting-documents.show', $viewParams);
                $isPdf = $document->isPdf();
                $uploadedBy = $document->uploadedByUser?->name ?? '—';
                $uploadedAt = $document->created_at
                    ? $document->created_at->timezone('Asia/Kolkata')->format('d M Y • h:i A')
                    : '—';
            @endphp
            <li class="flex flex-col gap-3 py-3.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <div @class([
                        'mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                        'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-400' => $isPdf,
                        'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-400' => ! $isPdf,
                    ])>
                        @if ($isPdf)
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h6l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 3v5h5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6M9 17h4" />
                            </svg>
                        @else
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100" title="{{ $document->original_file_name }}">
                            {{ $document->original_file_name }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $document->humanFileSize() }} • Uploaded by {{ $uploadedBy }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $uploadedAt }}
                        </p>
                    </div>
                </div>
                <div class="shrink-0 sm:self-center">
                    <a
                        href="{{ $viewUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10 sm:w-auto"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        View
                    </a>
                </div>
            </li>
        @endforeach
    </ul>
@endif
