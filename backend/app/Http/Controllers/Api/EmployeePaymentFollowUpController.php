<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentFollowUps\PaymentFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeePaymentFollowUpController extends Controller
{
    public function __construct(
        private readonly PaymentFollowUpService $followUps,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        return response()->json(
            $this->followUps->listForEmployee(
                $request->user(),
                $search !== '' ? $search : null,
            )
        );
    }

    public function show(Request $request, int $dealer): JsonResponse
    {
        return response()->json(
            $this->followUps->showForEmployee($request->user(), $dealer)
        );
    }

    public function store(Request $request, int $dealer): JsonResponse
    {
        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:2000'],
            'expected_amount' => ['nullable', 'numeric', 'gt:0'],
            'next_follow_up_date' => ['required', 'date'],
        ]);

        $detail = $this->followUps->addFollowUp(
            $request->user(),
            $dealer,
            (string) $validated['remark'],
            isset($validated['expected_amount']) ? (float) $validated['expected_amount'] : null,
            (string) $validated['next_follow_up_date'],
        );

        return response()->json([
            'message' => 'Follow-up saved.',
            ...$detail,
        ], 201);
    }
}
