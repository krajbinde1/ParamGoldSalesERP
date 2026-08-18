<?php

namespace App\Policies;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequests\PaymentRequestApproverResolver;

class PaymentRequestPolicy
{
    public function __construct(
        private readonly PaymentRequestApproverResolver $approvers,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->isAdminUser()
            || $user->isDirectorUser()
            || $this->approvers->isConfiguredApprover($user);
    }

    public function view(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdminUser();
    }

    public function update(User $user, PaymentRequest $paymentRequest): bool
    {
        return false;
    }

    public function delete(User $user, PaymentRequest $paymentRequest): bool
    {
        return false;
    }

    public function approveFirst(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->approvers->isFirstApprover($user)
            && $paymentRequest->canBeFirstApproved();
    }

    public function rejectFirst(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->approvers->isFirstApprover($user)
            && $paymentRequest->canBeFirstApproved();
    }

    public function approveSecond(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->approvers->isSecondApprover($user)
            && $paymentRequest->canBeSecondApproved();
    }

    public function rejectSecond(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->approvers->isSecondApprover($user)
            && $paymentRequest->canBeSecondApproved();
    }

    public function markPaid(User $user, PaymentRequest $paymentRequest): bool
    {
        return $user->isAdminUser()
            && $paymentRequest->canBeMarkedPaid();
    }

    public function remind(User $user, PaymentRequest $paymentRequest): bool
    {
        return $user->isAdminUser()
            && $paymentRequest->isAwaitingApproval();
    }

    public function remindPending(User $user): bool
    {
        return $user->isAdminUser();
    }

    public function manageSupportingDocuments(User $user, PaymentRequest $paymentRequest): bool
    {
        return $user->isAdminUser()
            && $paymentRequest->isAwaitingApproval();
    }

    public function viewSupportingDocument(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->view($user, $paymentRequest);
    }
}
