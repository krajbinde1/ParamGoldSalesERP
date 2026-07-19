<?php

namespace App\Console\Commands;

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Actions\Employees\ResetEmployeePassword;
use App\Actions\Employees\UpdateEmployeeLoginId;
use App\Enums\UserRole;
use App\Http\Controllers\Api\EmployeeAuthController;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VerifyEmployeeLoginAccess extends Command
{
    protected $signature = 'verify:employee-login-access';

    protected $description = 'Verify employee login ID management and password reset flows';

    /** @var list<array{label: string, passed: bool, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->info('Running employee login access verification...');

        $suffix = (string) random_int(100000, 999999);
        $mobile = '98'.str_pad(substr($suffix, 0, 8), 8, '0', STR_PAD_LEFT);
        $loginId = 'testlogin'.$suffix;
        $director = $this->resolveDirectorActor();
        $manager = $this->resolveManagerActor($suffix);
        $managerEmployee = Employee::query()->find($manager->employee_id);

        $employee = null;
        $managerEmployee = null;

        try {
            $this->check('Create employee with default mobile login ID', function () use ($mobile, $suffix, &$employee): void {
                $result = app(CreateEmployeeWithUserAccount::class)->execute([
                    'full_name' => 'Login Access Verify '.$suffix,
                    'mobile' => $mobile,
                    'email' => "login.verify.{$suffix}@example.com",
                    'department' => 'Sales',
                    'designation' => 'Sales Executive',
                    'joining_date' => '2026-07-16',
                    'salary' => 20000,
                    'base_location' => 'Pune',
                    'daily_allowance' => 200,
                    'travel_allowance_type' => 'actual_expense',
                    'company_card_issued' => false,
                    'monthly_travel_expense_limit' => 400,
                    'aadhaar_number' => '2'.str_pad(substr($suffix, 0, 11), 11, '1', STR_PAD_LEFT),
                    'pan_number' => 'ABCDE'.str_pad(substr($suffix, 0, 4), 4, '1', STR_PAD_LEFT).'A',
                    'bank_name' => 'Test Bank',
                    'account_number' => '12345'.$suffix,
                    'ifsc_code' => 'TEST0123456',
                    'status' => true,
                    'role' => UserRole::Employee->value,
                ]);

                $employee = $result->employee;

                if ($employee->user?->login_id !== $mobile) {
                    throw new \RuntimeException('Expected login ID '.$mobile.', got '.$employee->user?->login_id);
                }
            });

            $this->check('Change login ID without changing mobile', function () use ($employee, $loginId, $mobile, $director): void {
                app(UpdateEmployeeLoginId::class)->execute($employee, $loginId, $director);
                $employee->refresh();

                if ($employee->mobile !== $mobile) {
                    throw new \RuntimeException('Mobile changed unexpectedly.');
                }

                if ($employee->user?->login_id !== $loginId) {
                    throw new \RuntimeException('Login ID was not updated.');
                }
            });

            $this->check('Reset password to mobile last four digits and revoke tokens', function () use ($employee, $director, $mobile): void {
                $user = $employee->user;
                $user->createToken('employee-mobile');
                $expectedPassword = substr($mobile, -4);

                $reset = app(ResetEmployeePassword::class)->execute(
                    $employee,
                    $director,
                    ResetEmployeePassword::MODE_MOBILE_LAST_FOUR,
                );

                if ($reset['temporary_password'] !== $expectedPassword) {
                    throw new \RuntimeException('Unexpected temporary password returned.');
                }

                if (! Hash::check($expectedPassword, $user->fresh()->password)) {
                    throw new \RuntimeException('Password hash mismatch after reset.');
                }

                if (DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count() !== 0) {
                    throw new \RuntimeException('Tokens were not revoked.');
                }
            });

            $this->check('Old mobile login ID no longer works after change', function () use ($mobile): void {
                $response = app(EmployeeAuthController::class)->login(Request::create('/api/login', 'POST', [
                    'login_id' => $mobile,
                    'password' => substr($mobile, -4),
                ]));

                if ($response->getStatusCode() !== 422 || ! str_contains((string) $response->getContent(), 'Invalid login ID or password')) {
                    throw new \RuntimeException('Old mobile login ID should be rejected.');
                }
            });

            $this->check('Login succeeds with updated login ID and reset password', function () use ($loginId, $mobile): void {
                $response = app(EmployeeAuthController::class)->login(Request::create('/api/login', 'POST', [
                    'login_id' => $loginId,
                    'password' => substr($mobile, -4),
                ]));
                $payload = json_decode((string) $response->getContent(), true);

                if (($payload['success'] ?? false) !== true) {
                    throw new \RuntimeException('Login with updated login ID failed.');
                }
            });

            $this->check('Reset password using custom temporary password', function () use ($employee, $director, $loginId): void {
                $customPassword = 'Temp'.random_int(1000, 9999);

                $reset = app(ResetEmployeePassword::class)->execute(
                    $employee,
                    $director,
                    ResetEmployeePassword::MODE_CUSTOM,
                    $customPassword,
                    $customPassword,
                );

                $response = app(EmployeeAuthController::class)->login(Request::create('/api/login', 'POST', [
                    'login_id' => $loginId,
                    'password' => $reset['temporary_password'],
                ]));
                $payload = json_decode((string) $response->getContent(), true);

                if (($payload['success'] ?? false) !== true) {
                    throw new \RuntimeException('Login with custom temporary password failed.');
                }
            });

            $this->check('Manager cannot reset another manager password', function () use ($manager, $suffix): void {
                $target = app(CreateEmployeeWithUserAccount::class)->execute([
                    'full_name' => 'Manager Target '.$suffix,
                    'mobile' => '97'.str_pad(substr($suffix, 1, 8), 8, '2', STR_PAD_LEFT),
                    'email' => "manager.target.{$suffix}@example.com",
                    'department' => 'Sales',
                    'designation' => 'Manager',
                    'joining_date' => '2026-07-16',
                    'salary' => 30000,
                    'base_location' => 'Pune',
                    'daily_allowance' => 300,
                    'travel_allowance_type' => 'actual_expense',
                    'company_card_issued' => false,
                    'monthly_travel_expense_limit' => 500,
                    'aadhaar_number' => '3'.str_pad(substr($suffix, 0, 11), 11, '2', STR_PAD_LEFT),
                    'pan_number' => 'BCDEF'.str_pad(substr($suffix, 0, 4), 4, '2', STR_PAD_LEFT).'B',
                    'bank_name' => 'Test Bank',
                    'account_number' => '22345'.$suffix,
                    'ifsc_code' => 'TEST0123456',
                    'status' => true,
                    'role' => UserRole::Manager->value,
                ]);

                try {
                    app(ResetEmployeePassword::class)->execute(
                        $target->employee,
                        $manager,
                        ResetEmployeePassword::MODE_MOBILE_LAST_FOUR,
                    );
                } catch (AuthorizationException) {
                    app(DeleteEmployeeWithUserAccount::class)->execute($target->employee);

                    return;
                }

                app(DeleteEmployeeWithUserAccount::class)->execute($target->employee);
                throw new \RuntimeException('Manager was allowed to reset another manager password.');
            });
        } finally {
            if ($employee instanceof Employee) {
                app(DeleteEmployeeWithUserAccount::class)->execute($employee);
            }

            if ($managerEmployee instanceof Employee) {
                app(DeleteEmployeeWithUserAccount::class)->execute($managerEmployee);
            }
        }

        $passed = collect($this->results)->where('passed', true)->count();
        $failed = collect($this->results)->where('passed', false)->count();

        $this->newLine();
        foreach ($this->results as $result) {
            $icon = $result['passed'] ? 'PASS' : 'FAIL';
            $this->line("[{$icon}] {$result['label']}".($result['detail'] !== '' ? " — {$result['detail']}" : ''));
        }

        $this->newLine();
        $this->info("Verification complete: {$passed} passed, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(string $label, callable $callback): void
    {
        try {
            $callback();

            $this->results[] = [
                'label' => $label,
                'passed' => true,
                'detail' => '',
            ];
        } catch (\Throwable $exception) {
            $this->results[] = [
                'label' => $label,
                'passed' => false,
                'detail' => $exception->getMessage(),
            ];
        }
    }

    private function resolveDirectorActor(): User
    {
        $director = User::query()
            ->whereNull('employee_id')
            ->where('role', UserRole::Director->value)
            ->first();

        if ($director !== null) {
            return $director;
        }

        return User::query()
            ->whereNull('employee_id')
            ->firstOrFail();
    }

    private function resolveManagerActor(string $suffix): User
    {
        $result = app(CreateEmployeeWithUserAccount::class)->execute([
            'full_name' => 'Manager Actor '.$suffix,
            'mobile' => '96'.str_pad(substr($suffix, 0, 8), 8, '3', STR_PAD_LEFT),
            'email' => "manager.actor.{$suffix}@example.com",
            'department' => 'Sales',
            'designation' => 'Manager',
            'joining_date' => '2026-07-16',
            'salary' => 30000,
            'base_location' => 'Pune',
            'daily_allowance' => 300,
            'travel_allowance_type' => 'actual_expense',
            'company_card_issued' => false,
            'monthly_travel_expense_limit' => 500,
            'aadhaar_number' => '4'.str_pad(substr($suffix, 0, 11), 11, '3', STR_PAD_LEFT),
            'pan_number' => 'CDEFG'.str_pad(substr($suffix, 0, 4), 4, '3', STR_PAD_LEFT).'C',
            'bank_name' => 'Test Bank',
            'account_number' => '32345'.$suffix,
            'ifsc_code' => 'TEST0123456',
            'status' => true,
            'role' => UserRole::Manager->value,
        ]);

        return $result->employee->user;
    }
}
