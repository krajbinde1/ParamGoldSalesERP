<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use App\Services\Dealers\DealerLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerAccountController extends Controller
{
    public function __construct(
        private readonly DealerLedgerService $ledger,
        private readonly DealerAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->access->canViewAnyLedger($user)) {
            abort(403, 'You are not authorized to view dealer accounts.');
        }

        $query = Dealer::query()
            ->where('status', true)
            ->orderBy('firm_name');

        $this->access->scopeVisibleTo($query, $user);
        $this->ledger->scopeWithCurrentOutstanding($query);

        $dealers = $query
            ->get()
            ->map(function (Dealer $dealer): array {
                $outstanding = $this->money($dealer->getAttribute('current_outstanding') ?? $this->ledger->getOutstanding($dealer));

                return [
                    'id' => $dealer->id,
                    'dealer_code' => $dealer->dealer_code,
                    'firm_name' => $dealer->firm_name,
                    'owner_name' => $dealer->owner_name,
                    'mobile' => $dealer->mobile,
                    'village' => $dealer->village,
                    'current_outstanding' => $outstanding,
                ];
            })
            ->values();

        return response()->json(['data' => $dealers]);
    }

    public function show(Request $request, Dealer $dealer): JsonResponse
    {
        $this->authorizeLedger($request, $dealer);

        $summary = $this->ledger->getAccountSummary($dealer);

        return response()->json([
            'data' => [
                'id' => $dealer->id,
                'dealer_code' => $dealer->dealer_code,
                'firm_name' => $dealer->firm_name,
                'owner_name' => $dealer->owner_name,
                'mobile' => $dealer->mobile,
                'village' => $dealer->village,
                'taluka' => $dealer->taluka,
                'district' => $dealer->district,
                'state' => $dealer->state,
                'account_summary' => $summary,
            ],
        ]);
    }

    public function accountSummary(Request $request, Dealer $dealer): JsonResponse
    {
        $this->authorizeLedger($request, $dealer);

        return response()->json([
            'data' => $this->ledger->getAccountSummary($dealer),
        ]);
    }

    public function ledger(Request $request, Dealer $dealer): JsonResponse
    {
        $this->authorizeLedger($request, $dealer);

        return response()->json([
            'data' => $this->ledger->getLedger($dealer),
        ]);
    }

    private function authorizeLedger(Request $request, Dealer $dealer): void
    {
        if (! $this->access->canViewLedger($request->user(), $dealer)) {
            abort(403, 'You are not authorized to view this dealer ledger.');
        }
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
