<?php

namespace App\Actions\Employees;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RepairTestManagerEmployee
{
    public const MANAGER_LOGIN_ID = '9111111111';

    public const MANAGER_EMAIL = 'manager.test@paramgold.local';

    public const MANAGER_NAME = 'Test Manager';

    public const SALES_LOGIN_ID = '9444444444';

    public const SALES_EMAIL = 'sales.test@paramgold.local';

    public const SALES_NAME = 'Test Sales Employee';

    /**
     * @return array{
     *     manager_employee: Employee,
     *     manager_user: User,
     *     sales_employee: Employee,
     *     manager_created: bool,
     *     manager_linked: bool,
     *     sales_created: bool
     * }
     */
    public function execute(): array
    {
        return DB::transaction(function (): array {
            $beforeReports = $this->existingReportingManagers();

            $managerUser = $this->findOrCreateManagerUser();
            $managerEmployee = $this->findOrCreateManagerEmployee($managerUser);
            $managerCreated = $managerEmployee->wasRecentlyCreated;
            $linked = $this->linkManagerUser($managerUser, $managerEmployee);

            $sales = $this->findOrCreateTestSalesEmployee($managerEmployee);
            $salesCreated = $sales->wasRecentlyCreated;

            $this->assertUnchangedReportingManagers($beforeReports, $managerEmployee->id, $sales->id);

            return [
                'manager_employee' => $managerEmployee->fresh() ?? $managerEmployee,
                'manager_user' => $managerUser->fresh() ?? $managerUser,
                'sales_employee' => $sales->fresh() ?? $sales,
                'manager_created' => $managerCreated,
                'manager_linked' => $linked,
                'sales_created' => $salesCreated,
            ];
        });
    }

    /**
     * @return array<int, int|null>
     */
    private function existingReportingManagers(): array
    {
        return Employee::query()
            ->orderBy('id')
            ->pluck('reporting_manager_id', 'id')
            ->all();
    }

    /**
     * @param  array<int, int|null>  $before
     */
    private function assertUnchangedReportingManagers(array $before, int $managerEmployeeId, int $salesEmployeeId): void
    {
        $after = $this->existingReportingManagers();

        foreach ($before as $employeeId => $reportingManagerId) {
            if ((int) $employeeId === $salesEmployeeId) {
                continue;
            }

            if (! array_key_exists($employeeId, $after)) {
                continue;
            }

            if ((int) ($after[$employeeId] ?? 0) !== (int) ($reportingManagerId ?? 0)) {
                throw new \RuntimeException('Existing employee reporting managers must not change.');
            }
        }
    }

    private function findOrCreateManagerUser(): User
    {
        $user = User::query()
            ->where('login_id', self::MANAGER_LOGIN_ID)
            ->orWhere('email', self::MANAGER_EMAIL)
            ->first();

        if ($user !== null) {
            $user->fill([
                'name' => self::MANAGER_NAME,
                'login_id' => self::MANAGER_LOGIN_ID,
                'email' => $user->email ?: self::MANAGER_EMAIL,
                'role' => UserRole::Manager->value,
            ]);
            $user->save();

            return $user;
        }

        return app(CreateEmployeeWithUserAccount::class)->execute(
            $this->managerEmployeePayload(),
        )->employee->user()->firstOrFail();
    }

    private function findOrCreateManagerEmployee(User $user): Employee
    {
        if ($user->employee_id !== null) {
            $linked = Employee::query()->find($user->employee_id);
            if ($linked !== null) {
                $linked->fill([
                    'full_name' => self::MANAGER_NAME,
                    'mobile' => self::MANAGER_LOGIN_ID,
                    'email' => self::MANAGER_EMAIL,
                    'status' => true,
                    'designation' => UserRole::Manager->label(),
                ]);
                $linked->save();

                return $linked;
            }
        }

        $existing = Employee::query()->where('mobile', self::MANAGER_LOGIN_ID)->first();
        if ($existing !== null) {
            $existing->update([
                'full_name' => self::MANAGER_NAME,
                'email' => self::MANAGER_EMAIL,
                'status' => true,
                'designation' => UserRole::Manager->label(),
            ]);

            return $existing;
        }

        return Employee::query()->create($this->managerEmployeeAttributes());
    }

    private function linkManagerUser(User $user, Employee $employee): bool
    {
        $alreadyLinked = (int) $user->employee_id === (int) $employee->id
            && $user->role === UserRole::Manager->value;

        $user->fill([
            'employee_id' => $employee->id,
            'name' => $employee->full_name,
            'login_id' => self::MANAGER_LOGIN_ID,
            'email' => $employee->email ?: self::MANAGER_EMAIL,
            'role' => UserRole::Manager->value,
        ]);
        $user->save();

        return ! $alreadyLinked;
    }

    private function findOrCreateTestSalesEmployee(Employee $manager): Employee
    {
        $existing = Employee::query()
            ->where('mobile', self::SALES_LOGIN_ID)
            ->orWhere('email', self::SALES_EMAIL)
            ->first();

        if ($existing !== null) {
            if ((int) $existing->reporting_manager_id !== (int) $manager->id) {
                $existing->update(['reporting_manager_id' => $manager->id]);
            }

            return $existing;
        }

        $user = User::query()->where('login_id', self::SALES_LOGIN_ID)->first();
        if ($user?->employee_id) {
            $linked = Employee::query()->find($user->employee_id);
            if ($linked !== null) {
                $linked->update(['reporting_manager_id' => $manager->id]);

                return $linked;
            }
        }

        return app(CreateEmployeeWithUserAccount::class)->execute(
            array_merge($this->salesEmployeePayload(), [
                'reporting_manager_id' => $manager->id,
            ]),
        )->employee;
    }

    /**
     * @return array<string, mixed>
     */
    private function managerEmployeePayload(): array
    {
        return array_merge($this->managerEmployeeAttributes(), [
            'role' => UserRole::Manager->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function managerEmployeeAttributes(): array
    {
        return [
            'full_name' => self::MANAGER_NAME,
            'mobile' => self::MANAGER_LOGIN_ID,
            'email' => self::MANAGER_EMAIL,
            'department' => 'Sales',
            'designation' => UserRole::Manager->label(),
            'joining_date' => '2026-07-01',
            'salary' => 50000,
            'base_location' => 'Aurangabad',
            'daily_allowance' => 300,
            'travel_allowance_type' => 'actual_expense',
            'company_card_issued' => false,
            'monthly_travel_expense_limit' => 500,
            'aadhaar_number' => '234567890121',
            'pan_number' => 'ABCDE1231F',
            'bank_name' => 'Test Bank',
            'account_number' => '123456789011',
            'ifsc_code' => 'TEST0123456',
            'status' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesEmployeePayload(): array
    {
        return [
            'full_name' => self::SALES_NAME,
            'mobile' => self::SALES_LOGIN_ID,
            'email' => self::SALES_EMAIL,
            'department' => 'Sales',
            'designation' => 'Sales Officer',
            'joining_date' => '2026-07-01',
            'salary' => 25000,
            'base_location' => 'Aurangabad',
            'daily_allowance' => 300,
            'travel_allowance_type' => 'actual_expense',
            'company_card_issued' => false,
            'monthly_travel_expense_limit' => 500,
            'aadhaar_number' => '244444444444',
            'pan_number' => 'ABCDE4444F',
            'bank_name' => 'Test Bank',
            'account_number' => '123456789044',
            'ifsc_code' => 'TEST0123456',
            'status' => true,
            'role' => UserRole::Employee->value,
        ];
    }
}
