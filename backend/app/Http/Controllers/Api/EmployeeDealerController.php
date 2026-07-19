<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDealerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = Dealer::query()
            ->where('status', true)
            ->orderBy('firm_name');

        app(DealerAccessService::class)->scopeVisibleTo($query, $request->user());

        $dealers = $query
            ->get(['id', 'firm_name', 'owner_name', 'village', 'mobile'])
            ->map(fn (Dealer $dealer): array => [
                'id' => $dealer->id,
                'firm_name' => $dealer->firm_name,
                'owner_name' => $dealer->owner_name,
                'village' => $dealer->village,
                'mobile' => $dealer->mobile,
            ])
            ->values();

        return response()->json(['data' => $dealers]);
    }
}
