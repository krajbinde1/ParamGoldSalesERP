<?php

namespace App\Actions\Employees;

use App\Enums\UserRole;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CreateEmployeeWithUserAccount
{
    public function execute(array $employeeData): EmployeeAccountCreationResult
    {
        $employeeData = Employee::normalizeCreationData($employeeData);

        $validator = Validator::make($employeeData, Employee::creationRules());
        Employee::validateTravelKmLimits($validator);
        Employee::validateCompanyCard($validator);
        Employee::validateReportingManager($validator);
        $validator->validate();

        try {
            return DB::transaction(function () use ($employeeData): EmployeeAccountCreationResult {
                $role = UserRole::tryFromMixed($employeeData['role'] ?? UserRole::Employee->value)->value;
                unset($employeeData['role'], $employeeData['login_id'], $employeeData['employee_code']);

                $employee = Employee::query()->create($employeeData);

                if ($employee->user()->exists()) {
                    throw ValidationException::withMessages([
                        'mobile' => 'A user account already exists for this employee.',
                    ]);
                }

                $temporaryPassword = substr($employee->mobile, -4);

                app(ProvisionEmployeeUserAccount::class)->execute($employee, $role);

                $employee->refresh();

                return new EmployeeAccountCreationResult(
                    employee: $employee,
                    loginId: $employee->user?->login_id ?? $employee->mobile,
                    temporaryPassword: $temporaryPassword,
                );
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            Log::error('Employee creation failed during database save.', [
                'message' => $exception->getMessage(),
                'mobile' => $employeeData['mobile'] ?? null,
                'email' => $employeeData['email'] ?? null,
            ]);

            throw Employee::mapQueryExceptionToValidation($exception);
        } catch (\Throwable $exception) {
            Log::error('Employee creation failed unexpectedly.', [
                'message' => $exception->getMessage(),
                'mobile' => $employeeData['mobile'] ?? null,
                'email' => $employeeData['email'] ?? null,
            ]);

            throw $exception;
        }
    }
}
