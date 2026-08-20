<?php

namespace App\Actions\DealerApplications;

use App\Models\DealerApplication;
use App\Models\DealerApplicationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewDealerApplication
{
    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    public const ACTION_SEND_BACK = 'send_back';

    public function execute(
        DealerApplication $application,
        User $actor,
        string $action,
        string $stage,
        ?string $remark = null,
    ): DealerApplication {
        $remark = filled($remark) ? trim($remark) : null;

        if (in_array($action, [self::ACTION_REJECT, self::ACTION_SEND_BACK], true) && $remark === null) {
            throw ValidationException::withMessages([
                'remark' => 'A remark is required.',
            ]);
        }

        return DB::transaction(function () use ($application, $actor, $action, $stage, $remark): DealerApplication {
            /** @var DealerApplication $locked */
            $locked = DealerApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($stage === 'manager') {
                $this->assertManagerCanAct($locked, $action);
            } else {
                $this->assertAdminCanAct($locked, $action);
            }

            if ($action === self::ACTION_APPROVE && $stage === 'manager') {
                return $this->managerApprove($locked, $actor, $remark);
            }

            if ($action === self::ACTION_REJECT) {
                return $this->reject($locked, $actor, $stage, $remark ?? '');
            }

            return $this->sendBack($locked, $actor, $stage, $remark ?? '');
        });
    }

    private function managerApprove(DealerApplication $application, User $actor, ?string $remark): DealerApplication
    {
        $application->update([
            'status' => DealerApplication::STATUS_PENDING_ADMIN,
            'manager_id' => $actor->id,
            'manager_name' => $actor->name,
            'manager_approved_at' => now(),
            'manager_remark' => $remark,
            'last_action' => DealerApplicationEvent::MANAGER_APPROVED,
            'last_action_by' => $actor->id,
            'last_action_by_name' => $actor->name,
            'last_action_at' => now(),
            'last_action_remark' => $remark,
        ]);

        $application->recordEvent(DealerApplicationEvent::MANAGER_APPROVED, $actor, $remark);

        return $application->fresh(['employee', 'documents.uploadedByUser', 'events', 'dealer'])
            ?? $application;
    }

    private function reject(DealerApplication $application, User $actor, string $stage, string $remark): DealerApplication
    {
        $payload = [
            'status' => DealerApplication::STATUS_REJECTED,
            'last_action' => DealerApplicationEvent::REJECTED,
            'last_action_by' => $actor->id,
            'last_action_by_name' => $actor->name,
            'last_action_at' => now(),
            'last_action_remark' => $remark,
        ];

        if ($stage === 'manager') {
            $payload['manager_id'] = $actor->id;
            $payload['manager_name'] = $actor->name;
            $payload['manager_remark'] = $remark;
        } else {
            $payload['admin_id'] = $actor->id;
            $payload['admin_name'] = $actor->name;
            $payload['admin_remark'] = $remark;
        }

        $application->update($payload);
        $application->recordEvent(DealerApplicationEvent::REJECTED, $actor, $remark, ['stage' => $stage]);

        return $application->fresh(['employee', 'documents.uploadedByUser', 'events', 'dealer'])
            ?? $application;
    }

    private function sendBack(DealerApplication $application, User $actor, string $stage, string $remark): DealerApplication
    {
        $payload = [
            'status' => DealerApplication::STATUS_CORRECTION_REQUIRED,
            'last_action' => DealerApplicationEvent::SENT_BACK,
            'last_action_by' => $actor->id,
            'last_action_by_name' => $actor->name,
            'last_action_at' => now(),
            'last_action_remark' => $remark,
        ];

        if ($stage === 'manager') {
            $payload['manager_id'] = $actor->id;
            $payload['manager_name'] = $actor->name;
            $payload['manager_approved_at'] = null;
            $payload['manager_remark'] = $remark;
            $payload['admin_id'] = null;
            $payload['admin_name'] = null;
            $payload['admin_approved_at'] = null;
            $payload['admin_remark'] = null;
        } else {
            $payload['admin_id'] = $actor->id;
            $payload['admin_name'] = $actor->name;
            $payload['admin_remark'] = $remark;
        }

        $application->update($payload);
        $application->recordEvent(DealerApplicationEvent::SENT_BACK, $actor, $remark, ['stage' => $stage]);

        return $application->fresh(['employee', 'documents.uploadedByUser', 'events', 'dealer'])
            ?? $application;
    }

    private function assertManagerCanAct(DealerApplication $application, string $action): void
    {
        $allowed = match ($action) {
            self::ACTION_APPROVE, self::ACTION_SEND_BACK => $application->status === DealerApplication::STATUS_PENDING_MANAGER,
            self::ACTION_REJECT => in_array($application->status, [
                DealerApplication::STATUS_PENDING_MANAGER,
                DealerApplication::STATUS_CORRECTION_REQUIRED,
            ], true),
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages([
                'status' => 'This dealer application cannot be reviewed in its current status.',
            ]);
        }
    }

    private function assertAdminCanAct(DealerApplication $application, string $action): void
    {
        $allowed = match ($action) {
            self::ACTION_APPROVE => $application->status === DealerApplication::STATUS_PENDING_ADMIN,
            self::ACTION_SEND_BACK => in_array($application->status, [
                DealerApplication::STATUS_PENDING_MANAGER,
                DealerApplication::STATUS_PENDING_ADMIN,
            ], true),
            self::ACTION_REJECT => in_array($application->status, [
                DealerApplication::STATUS_PENDING_MANAGER,
                DealerApplication::STATUS_PENDING_ADMIN,
                DealerApplication::STATUS_CORRECTION_REQUIRED,
            ], true),
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages([
                'status' => 'This dealer application cannot be reviewed in its current status.',
            ]);
        }
    }
}
