<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles invoice/challan photo uploads for mobile inward drafts.
 * Stock posting remains server-side only via post endpoint.
 */
class RawMaterialInwardAttachmentController extends Controller
{
    use RespondsWithJson;

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', \App\Models\RawMaterialInward::class);

        $validated = $request->validate([
            'attachment' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,webp'],
        ]);

        $path = $validated['attachment']->store('raw-material-inwards', 'public');

        return $this->ok('Attachment uploaded.', [
            'attachment_path' => $path,
            'url' => asset('storage/'.$path),
        ]);
    }
}
