<?php

namespace App\Policies;

use App\Models\AttendancePunchOutCorrection;
use App\Models\User;
use App\Services\Orders\ManagerOrderAccessService;

class AttendancePunchOutCorrectionPolicy
{
    public function review(User $user, AttendancePunchOutCorrection $correction): bool
    {
        if (! $correction->isActionablePending()) {
            return false;
        }

        if ($user->isAdminUser() || $user->isDirectorUser()) {
            return true;
        }

        if (! $user->isManagerUser()) {
            return false;
        }

        $employeeId = (int) $correction->attendance?->employee_id;
        if ($employeeId <= 0) {
            return false;
        }

        return in_array(
            $employeeId,
            app(ManagerOrderAccessService::class)->directReportEmployeeIds($user),
            true,
        );
    }
}
