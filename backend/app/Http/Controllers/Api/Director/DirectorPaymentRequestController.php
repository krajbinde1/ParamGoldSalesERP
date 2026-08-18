<?php

namespace App\Http\Controllers\Api\Director;

use App\Actions\PaymentRequests\ApprovePaymentRequest;
use App\Actions\PaymentRequests\BulkApprovePaymentRequests;
use App\Actions\PaymentRequests\RejectPaymentRequest;
use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\PaymentRequests\PaymentRequestApproverResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectorPaymentRequestController extends Controller
{
    public function __construct(
        private readonly PaymentRequestApproverResolver $approvers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentRequest::class);

        $user = $request->user();
        $status = $request->query('status');

        $query = PaymentRequest::query()
            ->with(['createdByUser:id,name'])
            ->latest('id');

        if ($this->approvers->isFirstApprover($user) && ! $this->approvers->isSecondApprover($user)) {
            if ($status === 'pending') {
                $query->where('status', PaymentRequest::STATUS_PENDING_FIRST);
            } elseif ($status === 'approved') {
                $query->where('first_approved_by', $user->id)
                    ->whereIn('status', [
                        PaymentRequest::STATUS_PENDING_SECOND,
                        PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
                        PaymentRequest::STATUS_PAYMENT_DONE,
                    ]);
            } elseif ($status === 'rejected') {
                $query->where('status', PaymentRequest::STATUS_REJECTED_FIRST)
                    ->where('first_approved_by', $user->id);
            } elseif ($status === 'history' || $status === 'actioned') {
                $query->where(function ($q) use ($user): void {
                    $q->where('first_approved_by', $user->id)
                        ->where('status', '!=', PaymentRequest::STATUS_PENDING_FIRST);
                });
            } else {
                $query->where(function ($q) use ($user): void {
                    $q->where('status', PaymentRequest::STATUS_PENDING_FIRST)
                        ->orWhere('first_approved_by', $user->id);
                });
            }
        } elseif ($this->approvers->isSecondApprover($user) && ! $this->approvers->isFirstApprover($user)) {
            if ($status === 'pending') {
                $query->where('status', PaymentRequest::STATUS_PENDING_SECOND);
            } elseif ($status === 'approved') {
                $query->where('second_approved_by', $user->id)
                    ->whereIn('status', [
                        PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
                        PaymentRequest::STATUS_PAYMENT_DONE,
                    ]);
            } elseif ($status === 'rejected') {
                $query->where('status', PaymentRequest::STATUS_REJECTED_SECOND)
                    ->where('second_approved_by', $user->id);
            } elseif ($status === 'history' || $status === 'actioned') {
                $query->where(function ($q) use ($user): void {
                    $q->where('second_approved_by', $user->id)
                        ->where('status', '!=', PaymentRequest::STATUS_PENDING_SECOND);
                });
            } else {
                $query->where(function ($q) use ($user): void {
                    $q->where('status', PaymentRequest::STATUS_PENDING_SECOND)
                        ->orWhere('second_approved_by', $user->id);
                });
            }
        } elseif ($this->approvers->isConfiguredApprover($user)) {
            if ($status === 'pending') {
                $query->whereIn('status', [
                    PaymentRequest::STATUS_PENDING_FIRST,
                    PaymentRequest::STATUS_PENDING_SECOND,
                ]);
            } elseif ($status === 'approved') {
                $query->whereIn('status', [
                    PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
                    PaymentRequest::STATUS_PAYMENT_DONE,
                ]);
            } elseif ($status === 'rejected') {
                $query->whereIn('status', [
                    PaymentRequest::STATUS_REJECTED_FIRST,
                    PaymentRequest::STATUS_REJECTED_SECOND,
                ]);
            } elseif ($status === 'history') {
                $query->whereNotIn('status', [
                    PaymentRequest::STATUS_PENDING_FIRST,
                    PaymentRequest::STATUS_PENDING_SECOND,
                ]);
            }
        } else {
            if ($status === 'pending') {
                $query->whereIn('status', [
                    PaymentRequest::STATUS_PENDING_FIRST,
                    PaymentRequest::STATUS_PENDING_SECOND,
                ]);
            } elseif ($status === 'approved') {
                $query->whereIn('status', [
                    PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
                    PaymentRequest::STATUS_PAYMENT_DONE,
                ]);
            } elseif ($status === 'rejected') {
                $query->whereIn('status', [
                    PaymentRequest::STATUS_REJECTED_FIRST,
                    PaymentRequest::STATUS_REJECTED_SECOND,
                ]);
            } elseif ($status === 'history') {
                $query->whereNotIn('status', [
                    PaymentRequest::STATUS_PENDING_FIRST,
                    PaymentRequest::STATUS_PENDING_SECOND,
                ]);
            }
        }

        $items = $query->limit(200)->get();
        $pendingSummary = $this->pendingSummaryFor($user);

        return response()->json([
            'success' => true,
            'pending_count' => $pendingSummary['count'],
            'pending_total_amount' => $pendingSummary['amount'],
            'data' => $items->map(fn (PaymentRequest $pr): array => $this->listItem($pr, $user))->values(),
        ]);
    }

    public function show(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $this->authorize('view', $paymentRequest);

        $paymentRequest->load([
            'createdByUser:id,name',
            'firstApprovedByUser:id,name',
            'secondApprovedByUser:id,name',
            'paymentDoneByUser:id,name',
            'supportingDocuments.uploadedByUser:id,name',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->detail($paymentRequest, $request->user()),
        ]);
    }

    public function approve(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $updated = app(ApprovePaymentRequest::class)->execute(
            paymentRequest: $paymentRequest,
            actor: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment request approved.',
            'data' => $this->detail($updated->load([
                'createdByUser:id,name',
                'firstApprovedByUser:id,name',
                'secondApprovedByUser:id,name',
                'paymentDoneByUser:id,name',
                'supportingDocuments.uploadedByUser:id,name',
            ]), $request->user()),
        ]);
    }

    public function approveBulk(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentRequest::class);

        $validated = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer', 'exists:payment_requests,id'],
            'approve_all_pending' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $ids = $validated['ids'] ?? [];

        if (! empty($validated['approve_all_pending'])) {
            $ids = $this->pendingIdsFor($user);
        }

        $result = app(BulkApprovePaymentRequests::class)->execute($user, $ids);
        $summary = $this->pendingSummaryFor($user);

        return response()->json([
            'success' => true,
            'message' => "Approved {$result['approved']} payment request(s).",
            'approved' => $result['approved'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
            'pending_count' => $summary['count'],
            'pending_total_amount' => $summary['amount'],
        ]);
    }

    public function reject(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $validated = $request->validate([
            'remark' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $updated = app(RejectPaymentRequest::class)->execute(
            paymentRequest: $paymentRequest,
            actor: $request->user(),
            remark: $validated['remark'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment request rejected.',
            'data' => $this->detail($updated->load([
                'createdByUser:id,name',
                'firstApprovedByUser:id,name',
                'secondApprovedByUser:id,name',
                'paymentDoneByUser:id,name',
                'supportingDocuments.uploadedByUser:id,name',
            ]), $request->user()),
        ]);
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentRequest::class);
        $summary = $this->pendingSummaryFor($request->user());

        return response()->json([
            'success' => true,
            'pending_count' => $summary['count'],
            'pending_total_amount' => $summary['amount'],
        ]);
    }

    /**
     * @return array{count: int, amount: float}
     */
    private function pendingSummaryFor($user): array
    {
        $query = PaymentRequest::query();

        if ($this->approvers->isFirstApprover($user) && ! $this->approvers->isSecondApprover($user)) {
            $query->where('status', PaymentRequest::STATUS_PENDING_FIRST);
        } elseif ($this->approvers->isSecondApprover($user) && ! $this->approvers->isFirstApprover($user)) {
            $query->where('status', PaymentRequest::STATUS_PENDING_SECOND);
        } else {
            $query->whereIn('status', [
                PaymentRequest::STATUS_PENDING_FIRST,
                PaymentRequest::STATUS_PENDING_SECOND,
            ]);
        }

        return [
            'count' => (clone $query)->count(),
            'amount' => (float) (clone $query)->sum('amount'),
        ];
    }

    /**
     * @return list<int>
     */
    private function pendingIdsFor($user): array
    {
        $query = PaymentRequest::query()->orderBy('id');

        if ($this->approvers->isFirstApprover($user) && ! $this->approvers->isSecondApprover($user)) {
            $query->where('status', PaymentRequest::STATUS_PENDING_FIRST);
        } elseif ($this->approvers->isSecondApprover($user) && ! $this->approvers->isFirstApprover($user)) {
            $query->where('status', PaymentRequest::STATUS_PENDING_SECOND);
        } else {
            $query->whereIn('status', [
                PaymentRequest::STATUS_PENDING_FIRST,
                PaymentRequest::STATUS_PENDING_SECOND,
            ]);
        }

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(PaymentRequest $pr, $user): array
    {
        return [
            'id' => $pr->id,
            'request_no' => $pr->request_no,
            'vendor_name' => $pr->vendor_name,
            'vendor_mobile' => $pr->vendor_mobile,
            'amount' => (float) $pr->amount,
            'remark' => $pr->remark,
            'status' => $pr->status,
            'status_label' => $pr->displayStatusLabel(),
            'current_stage' => $pr->currentStageLabel(),
            'created_at' => optional($pr->created_at)?->timezone('Asia/Kolkata')->toIso8601String(),
            'created_by' => $pr->createdByUser?->name,
            'can_approve' => $user->can('approveFirst', $pr) || $user->can('approveSecond', $pr),
            'can_reject' => $user->can('rejectFirst', $pr) || $user->can('rejectSecond', $pr),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(PaymentRequest $pr, $user): array
    {
        return array_merge($this->listItem($pr, $user), [
            'created_by' => $pr->createdByUser?->name,
            'created_by_id' => $pr->created_by,
            'first_approved_by' => $pr->first_approved_by,
            'first_approver_name' => $pr->first_approver_name,
            'first_approver_role' => $pr->first_approver_role,
            'first_approved_at' => $pr->first_approved_at?->timezone('Asia/Kolkata')?->toIso8601String(),
            'first_rejection_remark' => $pr->first_rejection_remark,
            'second_approved_by' => $pr->second_approved_by,
            'second_approver_name' => $pr->second_approver_name,
            'second_approver_role' => $pr->second_approver_role,
            'second_approved_at' => $pr->second_approved_at?->timezone('Asia/Kolkata')?->toIso8601String(),
            'second_rejection_remark' => $pr->second_rejection_remark,
            'payment_done_at' => $pr->payment_done_at?->timezone('Asia/Kolkata')?->toIso8601String(),
            'payment_remark' => $pr->payment_remark,
            'payment_proof_url' => $pr->paymentProofUrl(),
            'payment_status' => $pr->paymentStatusLabel(),
            'timeline' => $pr->approvalTimeline(),
            'supporting_documents' => $pr->supportingDocuments
                ->map(fn ($doc): array => $doc->toApiArray())
                ->values()
                ->all(),
        ]);
    }
}
