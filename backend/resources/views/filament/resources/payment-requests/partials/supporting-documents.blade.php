<div class="space-y-3">
    @if ($documents->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No Supporting Documents</p>
    @else
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
            Supporting Documents ({{ $documents->count() }})
        </p>
        <ul class="divide-y divide-gray-200 dark:divide-white/10 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden bg-white dark:bg-gray-900">
            @foreach ($documents as $document)
                <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="font-medium text-gray-900 dark:text-white truncate">
                            {{ $document->isPdf() ? '📄' : '🖼' }}
                            {{ $document->original_file_name }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ $document->humanFileSize() }}
                            @if ($document->uploadedByUser?->name)
                                · Uploaded by {{ $document->uploadedByUser->name }}
                            @endif
                            @if ($document->created_at)
                                · {{ $document->created_at->timezone('Asia/Kolkata')->format('d M Y • h:i A') }}
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0">
                        <a
                            href="{{ route('payment-requests.supporting-documents.show', [$document->payment_request_id, $document->id]) }}"
                            target="_blank"
                            rel="noopener"
                            class="text-sm font-semibold text-primary-600 hover:underline"
                        >
                            View
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
