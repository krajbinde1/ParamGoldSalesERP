<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use App\Services\Dealers\DealerCreditExposureService;
use App\Services\Dealers\DealerCreditLimitNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDealerCreditController extends Controller
{
    public function __construct(
        private readonly DealerCreditExposureService $exposure,
        private readonly DealerCreditLimitNotifier $notifier,
        private readonly DealerAccessService $access,
    ) {}

    public function show(Request $request, Dealer $dealer): JsonResponse
    {
        if (! $this->access->canAccessDealer($request->user(), $dealer)) {
            abort(403, 'You are not authorized to view this dealer credit limit.');
        }

        $validated = $request->validate([
            'order_amount' => ['nullable', 'numeric', 'min:0'],
            'exclude_order_id' => ['nullable', 'integer'],
        ]);

        $assessment = $this->exposure->assess(
            $dealer,
            (float) ($validated['order_amount'] ?? 0),
            isset($validated['exclude_order_id']) ? (int) $validated['exclude_order_id'] : null,
        );

        $this->notifier->syncThresholdWarning($dealer, $assessment);

        return response()->json([
            'data' => $assessment->toArray(),
        ]);
    }
}
