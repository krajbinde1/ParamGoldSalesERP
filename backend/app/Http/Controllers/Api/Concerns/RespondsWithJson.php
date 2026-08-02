<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

trait RespondsWithJson
{
    /**
     * Standard success envelope: { success, message, data }.
     */
    protected function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Standard failure envelope (used for non-validation failures).
     */
    protected function fail(string $message, mixed $data = null, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Standard paginated success envelope: { success, message, data, meta }.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  (callable(mixed): mixed)|null  $map
     */
    protected function paginated(string $message, LengthAwarePaginator $paginator, ?callable $map = null): JsonResponse
    {
        /** @var Collection<int, mixed> $items */
        $items = collect($paginator->items());

        if ($map !== null) {
            $items = $items->map($map)->values();
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
